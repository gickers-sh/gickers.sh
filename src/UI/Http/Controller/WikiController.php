<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Access\Application\ServerPolicy;
use App\Identity\Domain\Model\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\UI\Http\ReadmeRenderer;
use App\Wiki\Application\DeleteWikiPage;
use App\Wiki\Application\Query\WikiQuery;
use App\Wiki\Application\SaveWikiPage;
use App\Wiki\Domain\WikiSlug;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Global server Wiki web interface (`/wiki`):
 * - `GET /wiki`: Wiki home (shows `home` page or first page, sidebar by category, and search results when `?q=` is given)
 * - `GET /wiki/new` & `POST /wiki/save`: Admin-only creation/update of Markdown wiki pages
 * - `GET /wiki/edit/{slug}`: Admin-only edit form for an existing wiki page
 * - `POST /wiki/delete/{slug}`: Admin-only deletion of a wiki page
 * - `GET /wiki/{slug}`: View a specific wiki page
 */
final readonly class WikiController
{
    private const CSP = "default-src 'none'; style-src 'self'; script-src 'self'; connect-src 'self'; img-src 'self' data:; font-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";

    public function __construct(
        private WikiQuery $wikiQuery,
        private SaveWikiPage $saveWikiPage,
        private DeleteWikiPage $deleteWikiPage,
        private ReadmeRenderer $readmeRenderer,
        private ServerSettings $settings,
        private Security $security,
        private \Twig\Environment $twig,
        private ServerPolicy $serverPolicy,
        #[Autowire('%kernel.secret%')]
        private string $appSecret = 'gickers-sh-secret',
    ) {
    }

    #[Route('/wiki', name: 'app_wiki_index', methods: ['GET'], priority: 25)]
    public function index(Request $request): Response
    {
        $user = $this->domainUser();
        if (null === $user && !$this->serverPolicy->anonAccess()->canRead()) {
            throw new AccessDenied('unauthorized');
        }

        $isAdmin = $user?->isAdmin() ?? false;
        $sshPrefix = $this->sshPrefix();

        $searchQuery = trim((string) $request->query->get('q', ''));
        $searchResults = '' !== $searchQuery ? $this->wikiQuery->search($searchQuery) : null;

        $allPages = $this->wikiQuery->all();
        $grouped = $this->wikiQuery->groupedByCategory();

        $activePage = null;
        if (null === $searchResults && [] !== $allPages) {
            $activePage = $this->wikiQuery->find('home') ?? $allPages[0];
        }

        $prevPage = null;
        $nextPage = null;
        if (null !== $activePage) {
            foreach ($allPages as $idx => $p) {
                if ($p->slugString() === $activePage->slugString()) {
                    $prevPage = $allPages[$idx - 1] ?? null;
                    $nextPage = $allPages[$idx + 1] ?? null;
                    break;
                }
            }
        }

        $renderedHtml = null !== $activePage ? $this->readmeRenderer->render($activePage->content()) : null;

        return $this->htmlResponse($this->twig->render('wiki/index.html.twig', [
            'serverName' => $this->settings->name ?: 'gickers.sh',
            'currentUsername' => $user?->username(),
            'isAdmin' => $isAdmin,
            'sshPrefix' => $sshPrefix,
            'groupedPages' => $grouped,
            'totalPages' => \count($allPages),
            'activePage' => $activePage,
            'renderedHtml' => $renderedHtml,
            'prevPage' => $prevPage,
            'nextPage' => $nextPage,
            'searchQuery' => $searchQuery,
            'searchResults' => $searchResults,
            'csrfToken' => null !== $user && $user->isAdmin() ? $this->csrfToken($user, $request) : '',
        ]));
    }

    #[Route('/wiki/new', name: 'app_wiki_new', methods: ['GET'], priority: 30)]
    public function create(Request $request): Response
    {
        $user = $this->requireAdmin();
        $sshPrefix = $this->sshPrefix();
        $prefillSlug = trim((string) $request->query->get('slug', ''));

        return $this->htmlResponse($this->twig->render('wiki/edit.html.twig', [
            'serverName' => $this->settings->name ?: 'gickers.sh',
            'currentUsername' => $user->username(),
            'isAdmin' => true,
            'sshPrefix' => $sshPrefix,
            'groupedPages' => $this->wikiQuery->groupedByCategory(),
            'page' => null,
            'form' => [
                'slug' => $prefillSlug,
                'title' => '',
                'category' => 'General',
                'position' => 0,
                'content' => '',
            ],
            'error' => null,
            'csrfToken' => $this->csrfToken($user, $request),
        ]));
    }

    #[Route('/wiki/edit/{slug}', name: 'app_wiki_edit', requirements: ['slug' => '.+'], methods: ['GET'], priority: 30)]
    public function edit(Request $request, string $slug): Response
    {
        $user = $this->requireAdmin();
        $sshPrefix = $this->sshPrefix();

        $page = $this->wikiQuery->find($slug);
        if (null === $page) {
            throw new NotFoundHttpException(sprintf('Wiki page "%s" not found.', $slug));
        }

        return $this->htmlResponse($this->twig->render('wiki/edit.html.twig', [
            'serverName' => $this->settings->name ?: 'gickers.sh',
            'currentUsername' => $user->username(),
            'isAdmin' => true,
            'sshPrefix' => $sshPrefix,
            'groupedPages' => $this->wikiQuery->groupedByCategory(),
            'page' => $page,
            'form' => [
                'slug' => $page->slugString(),
                'title' => $page->title(),
                'category' => $page->category(),
                'position' => $page->position(),
                'content' => $page->content(),
            ],
            'error' => null,
            'csrfToken' => $this->csrfToken($user, $request),
        ]));
    }

    #[Route('/wiki/save', name: 'app_wiki_save', methods: ['POST'], priority: 30)]
    public function save(Request $request): Response
    {
        $user = $this->requireAdmin();
        $this->verifyCsrf($user, $request);

        $rawSlug = trim((string) $request->request->get('slug', ''));
        $title = trim((string) $request->request->get('title', ''));
        $category = trim((string) $request->request->get('category', 'General'));
        $position = (int) $request->request->get('position', '0');
        $content = (string) $request->request->get('content', '');

        try {
            if ('' === trim($content)) {
                throw new InvalidInput('Wiki page content cannot be empty.');
            }
            $saved = ($this->saveWikiPage)(
                actor: $user,
                slug: $rawSlug,
                title: '' !== $title ? $title : null,
                content: $content,
                category: '' !== $category ? $category : 'General',
                position: $position,
            );

            return new RedirectResponse('/wiki/'.$saved->slugString(), Response::HTTP_SEE_OTHER);
        } catch (InvalidInput $e) {
            $existing = null;
            try {
                $existing = '' !== $rawSlug ? $this->wikiQuery->find($rawSlug) : null;
            } catch (InvalidInput) {
            }

            return $this->htmlResponse(
                $this->twig->render('wiki/edit.html.twig', [
                    'serverName' => $this->settings->name ?: 'gickers.sh',
                    'currentUsername' => $user->username(),
                    'isAdmin' => true,
                    'sshPrefix' => $this->sshPrefix(),
                    'groupedPages' => $this->wikiQuery->groupedByCategory(),
                    'page' => $existing,
                    'form' => [
                        'slug' => $rawSlug,
                        'title' => $title,
                        'category' => $category,
                        'position' => $position,
                        'content' => $content,
                    ],
                    'error' => $e->getMessage(),
                    'csrfToken' => $this->csrfToken($user, $request),
                ]),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
    }

    #[Route('/wiki/delete/{slug}', name: 'app_wiki_delete', requirements: ['slug' => '.+'], methods: ['POST'], priority: 30)]
    public function delete(Request $request, string $slug): Response
    {
        $user = $this->requireAdmin();
        $this->verifyCsrf($user, $request);

        ($this->deleteWikiPage)($user, $slug);

        return new RedirectResponse('/wiki', Response::HTTP_SEE_OTHER);
    }

    #[Route('/wiki/{slug}', name: 'app_wiki_show', requirements: ['slug' => '.+'], methods: ['GET'], priority: 25)]
    public function show(Request $request, string $slug): Response
    {
        $user = $this->domainUser();
        if (null === $user && !$this->serverPolicy->anonAccess()->canRead()) {
            throw new AccessDenied('unauthorized');
        }

        $isAdmin = $user?->isAdmin() ?? false;
        $sshPrefix = $this->sshPrefix();

        try {
            $slugObj = WikiSlug::fromInput($slug);
        } catch (InvalidInput) {
            throw new NotFoundHttpException(sprintf('Wiki page "%s" not found.', $slug));
        }

        $page = $this->wikiQuery->find($slugObj);
        if (null === $page) {
            throw new NotFoundHttpException(sprintf('Wiki page "%s" not found.', $slug));
        }

        $allPages = $this->wikiQuery->all();
        $grouped = $this->wikiQuery->groupedByCategory();
        $renderedHtml = $this->readmeRenderer->render($page->content());

        $prevPage = null;
        $nextPage = null;
        foreach ($allPages as $idx => $p) {
            if ($p->slugString() === $page->slugString()) {
                $prevPage = $allPages[$idx - 1] ?? null;
                $nextPage = $allPages[$idx + 1] ?? null;
                break;
            }
        }

        return $this->htmlResponse($this->twig->render('wiki/index.html.twig', [
            'serverName' => $this->settings->name ?: 'gickers.sh',
            'currentUsername' => $user?->username(),
            'isAdmin' => $isAdmin,
            'sshPrefix' => $sshPrefix,
            'groupedPages' => $grouped,
            'totalPages' => \count($allPages),
            'activePage' => $page,
            'renderedHtml' => $renderedHtml,
            'prevPage' => $prevPage,
            'nextPage' => $nextPage,
            'searchQuery' => '',
            'searchResults' => null,
            'csrfToken' => null !== $user && $user->isAdmin() ? $this->csrfToken($user, $request) : '',
        ]));
    }

    private function requireAdmin(): User
    {
        $user = $this->domainUser();
        if (null === $user || !$user->isAdmin()) {
            throw new AccessDenied('unauthorized');
        }

        return $user;
    }

    private function csrfToken(User $user, Request $request): string
    {
        $material = (string) ($request->cookies->get('gickers_session') ?? $request->cookies->get('codes_session', (string) $request->headers->get('Authorization', '')));

        return hash_hmac('sha256', 'wiki-admin:'.$user->id().':'.$user->webTokenVersion().':'.$material, $this->appSecret);
    }

    private function verifyCsrf(User $user, Request $request): void
    {
        $submitted = (string) $request->request->get('_token', '');
        $expected = $this->csrfToken($user, $request);
        if ('' === $submitted || !hash_equals($expected, $submitted)) {
            throw new AccessDenied('invalid csrf token');
        }
    }

    private function htmlResponse(string $html, int $status = Response::HTTP_OK): Response
    {
        return new Response(
            $html,
            $status,
            [
                'Content-Type' => 'text/html; charset=utf-8',
                'Content-Security-Policy' => self::CSP,
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function domainUser(): ?User
    {
        $securityUser = $this->security->getUser();

        return $securityUser instanceof SecurityUser ? $securityUser->user() : null;
    }

    private function sshPrefix(): string
    {
        $raw = trim($this->settings->sshPublicUrl);
        if ('' === $raw) {
            return 'ssh gickers.sh';
        }

        $url = str_contains($raw, '://') ? $raw : 'ssh://'.$raw;
        $parsed = parse_url($url);
        if (false === $parsed || !isset($parsed['host'])) {
            return 'ssh '.$raw;
        }

        $host = $parsed['host'];
        $port = isset($parsed['port']) && 22 !== (int) $parsed['port'] ? (int) $parsed['port'] : null;
        $user = $parsed['user'] ?? null;
        $target = null !== $user && '' !== $user ? "{$user}@{$host}" : $host;

        return null !== $port ? "ssh -p {$port} {$target}" : "ssh {$target}";
    }
}

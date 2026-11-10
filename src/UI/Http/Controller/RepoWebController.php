<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Access\Application\AccessResolver;
use App\Identity\Domain\Model\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\UI\Http\ReadmeRenderer;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lightweight repository page: `GET /{repo}` renders the README, clone command and
 * repository metadata as escaped HTML. Private repositories remain indistinguishable
 * from missing repositories to callers without read access.
 *
 * This controller also dispatches Go's `?go-get=1` discovery requests.
 */
final readonly class RepoWebController
{
    public function __construct(
        private RepoStore $repoStore,
        private AccessResolver $accessResolver,
        private GitRepositoryReader $reader,
        private BareRepositoryStorage $storage,
        private ServerSettings $settings,
        private Security $security,
        private GoGetController $goGet,
        private \Twig\Environment $twig,
        private ReadmeRenderer $readmeRenderer,
    ) {
    }

    // Lowest-priority catch-all: every specific git/LFS/raw/release route (all of which carry
    // a fixed suffix) wins over it, so only otherwise-unmatched GETs reach the page or the 404.
    #[Route('/{repo}', requirements: ['repo' => '.+'], methods: ['GET'], priority: -110)]
    public function __invoke(Request $request, string $repo): Response
    {
        // Go tooling's `?go-get=1` discovery request targets the same URL and has its own handler.
        // It is dispatched here: a route `condition` on the query string would need
        // symfony/expression-language, which is not installed (it would 500 every request).
        if ('1' === $request->query->get('go-get')) {
            return ($this->goGet)($request, $repo);
        }

        try {
            // Sanitizing strips surrounding slashes and a trailing ".git" before validating.
            $name = RepoName::fromInput($repo);
        } catch (InvalidInput) {
            return $this->notFound();
        }

        $entity = $this->repoStore->byName($name);
        if (null === $entity || !$this->accessResolver->levelFor($this->domainUser(), $name, $entity)->canRead()) {
            return $this->notFound();
        }
        if (!$this->storage->exists($name)) {
            return $this->notFound();
        }

        $git = $this->storage->open($name);
        $hasCommits = null !== $this->reader->objectType($git, 'HEAD');
        $readme = $hasCommits ? $this->reader->readme($git) : null;
        $readmeHtml = null === $readme ? null : $this->readmeRenderer->render($readme['content']);

        $cloneCommand = $this->cloneCommand($name);

        $response = new Response(
            $this->twig->render('repo/show.html.twig', [
                'repository' => $name->value,
                'description' => $entity->description(),
                'cloneCommand' => $cloneCommand,
                'readmePath' => $readme['path'] ?? null,
                'readmeHtml' => $readmeHtml,
                'empty' => !$hasCommits,
                'serverName' => $this->settings->name,
            ]),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=utf-8',
                'Content-Security-Policy' => "default-src 'none'; style-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
                'X-Content-Type-Options' => 'nosniff',
            ],
        );

        return $response;
    }

    /**
     * The `git clone` command advertised for the repository, or an empty string when no
     * public SSH URL is configured. Same format as the TUI's `RepoState::cloneCommand()`.
     */
    private function cloneCommand(RepoName $name): string
    {
        if ('' === $this->settings->sshPublicUrl) {
            return '';
        }

        return 'git clone '.rtrim($this->settings->sshPublicUrl, '/').'/'.$name->value.'.git';
    }

    private function notFound(): Response
    {
        return new Response("Not Found\n", Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain']);
    }

    private function domainUser(): ?User
    {
        $securityUser = $this->security->getUser();

        return $securityUser instanceof SecurityUser ? $securityUser->user() : null;
    }
}

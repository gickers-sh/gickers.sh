<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Identity\Domain\Model\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Repo\Domain\CollaboratorStore;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoStore;
use App\Shared\Infrastructure\Config\ServerSettings;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /`: the signed-in user's "My Repositories" dashboard — the repositories
 * they own or collaborate on, each linking to its page. This is where `web login`
 * lands after setting the session cookie. Anonymous visitors get sign-in guidance.
 */
final readonly class MyRepositoriesController
{
    public function __construct(
        private RepoStore $repoStore,
        private CollaboratorStore $collaborators,
        private ServerSettings $settings,
        private Security $security,
        private \Twig\Environment $twig,
    ) {}

    #[Route('/', methods: ['GET'])]
    public function __invoke(): Response
    {
        $user = $this->domainUser();
        $sshPrefix = $this->sshPrefix();
        $sshUrlBase = $this->sshUrlBase();
        $sshLoginUrl = '' !== $sshUrlBase ? $sshUrlBase . '/web%20login' : 'ssh://' . ($this->settings->name ?: 'codes.sh') . '/web%20login';

        $html = $this->twig->render('web/my-repositories.html.twig', [
            'serverName' => $this->settings->name,
            'username' => $user?->username(),
            'sshPrefix' => $sshPrefix,
            'sshUrlBase' => $sshUrlBase,
            'sshLoginUrl' => $sshLoginUrl,
            'repositories' => null === $user ? [] : $this->repositoriesFor($user),
        ]);

        return new Response(
            $html,
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=utf-8',
                'Content-Security-Policy' => "default-src 'none'; style-src 'self'; script-src 'self'; connect-src 'self'; img-src 'self' data:; font-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * Repositories the user owns or collaborates on, ordered by name, each with
     * the user's role on it. Hidden/private repositories are included because
     * they belong to the user.
     *
     * @return list<array{name: string, description: string, role: string, private: bool, hidden: bool}>
     */
    private function repositoriesFor(User $user): array
    {
        $items = [];
        foreach ($this->repoStore->all() as $repo) {
            $role = $this->roleFor($user, $repo);
            if (null === $role) {
                continue;
            }
            $items[] = [
                'name' => $repo->nameString(),
                'description' => $repo->description(),
                'role' => $role,
                'private' => $repo->isPrivate(),
                'hidden' => $repo->isHidden(),
            ];
        }

        usort($items, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $items;
    }

    /**
     * The user's role on the repository, or null when it is neither owned nor
     * shared with them (public repositories of others are not "theirs").
     */
    private function roleFor(User $user, Repo $repo): ?string
    {
        if ($repo->isOwnedBy($user)) {
            return 'Owner';
        }

        $collab = $this->collaborators->find($repo, $user);
        if (null === $collab) {
            return null;
        }

        return match ($collab->accessLevel()->toString()) {
            'admin-access' => 'Admin',
            'read-write' => 'Write',
            default => 'Read',
        };
    }

    private function domainUser(): ?User
    {
        $securityUser = $this->security->getUser();

        return $securityUser instanceof SecurityUser ? $securityUser->user() : null;
    }

    /**
     * Base SSH invocation advertised for this server (e.g. `ssh codes.sh`).
     */
    private function sshPrefix(): string
    {
        $raw = trim($this->settings->sshPublicUrl);
        if ('' === $raw) {
            return 'ssh codes.sh';
        }

        $url = str_contains($raw, '://') ? $raw : 'ssh://' . $raw;
        $parsed = parse_url($url);
        if (false === $parsed || !isset($parsed['host'])) {
            return 'ssh ' . $raw;
        }

        $host = $parsed['host'];
        $port = isset($parsed['port']) && 22 !== (int) $parsed['port'] ? (int) $parsed['port'] : null;
        $user = $parsed['user'] ?? null;
        $target = null !== $user && '' !== $user ? "{$user}@{$host}" : $host;

        return null !== $port ? "ssh -p {$port} {$target}" : "ssh {$target}";
    }

    /**
     * Base of an `ssh://` URL for this server (e.g. `ssh://codes.sh:2223`), or an
     * empty string when no public SSH URL is configured.
     */
    private function sshUrlBase(): string
    {
        $raw = trim($this->settings->sshPublicUrl);
        if ('' === $raw) {
            return '';
        }

        $url = str_contains($raw, '://') ? $raw : 'ssh://' . $raw;
        $parsed = parse_url($url);
        if (false === $parsed || !isset($parsed['host'])) {
            return '';
        }

        $authority = $parsed['host'];
        if (isset($parsed['user']) && '' !== $parsed['user']) {
            $authority = $parsed['user'] . '@' . $authority;
        }
        if (isset($parsed['port'])) {
            $authority .= ':' . (int) $parsed['port'];
        }

        return 'ssh://' . $authority;
    }
}

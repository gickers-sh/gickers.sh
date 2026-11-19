<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Access\Application\AccessResolver;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Repo\Domain\CollaboratorStore;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoStore;
use App\Shared\Infrastructure\Config\ServerSettings;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * User repositories page: `GET /@{username}` lists a user's repositories.
 * When viewed by the authenticated owner, it serves as the "My Repositories" dashboard
 * (including private/hidden repositories and collaboration roles).
 * `GET /my-repositories` redirects authenticated users to their own profile.
 */
final readonly class UserRepositoriesController
{
    public function __construct(
        private RepoStore $repoStore,
        private CollaboratorStore $collaborators,
        private UserStore $userStore,
        private AccessResolver $accessResolver,
        private ServerSettings $settings,
        private Security $security,
        private \Twig\Environment $twig,
    ) {}

    #[Route('/@{username}', name: 'user_repositories', methods: ['GET'], requirements: ['username' => '[\p{L}\p{N}-]+'])]
    public function profile(string $username): Response
    {
        $targetUser = $this->userStore->byUsername($username);
        if (null === $targetUser) {
            return $this->notFound();
        }

        $viewer = $this->domainUser();
        $isOwner = null !== $viewer && $viewer->id() === $targetUser->id();
        $sshPrefix = $this->sshPrefix();

        $repositories = $isOwner
            ? $this->repositoriesForOwner($targetUser)
            : $this->repositoriesForViewer($targetUser, $viewer);

        $html = $this->twig->render('user/repositories.html.twig', [
            'serverName' => $this->settings->name ?: 'codes.sh',
            'targetUsername' => $targetUser->username(),
            'currentUsername' => $viewer?->username(),
            'isOwner' => $isOwner,
            'sshPrefix' => $sshPrefix,
            'repositories' => $repositories,
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

    #[Route('/my-repositories', name: 'my_repositories', methods: ['GET'])]
    public function myRepositories(): Response
    {
        $viewer = $this->domainUser();
        if (null !== $viewer) {
            return new RedirectResponse('/@' . $viewer->username(), Response::HTTP_SEE_OTHER);
        }

        $sshPrefix = $this->sshPrefix();

        $html = $this->twig->render('user/repositories.html.twig', [
            'serverName' => $this->settings->name ?: 'codes.sh',
            'targetUsername' => null,
            'currentUsername' => null,
            'isOwner' => false,
            'sshPrefix' => $sshPrefix,
            'repositories' => [],
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
    private function repositoriesForOwner(User $user): array
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
     * Repositories belonging to targetUser visible to viewer.
     *
     * @return list<array{name: string, description: string, role: string, private: bool, hidden: bool}>
     */
    private function repositoriesForViewer(User $targetUser, ?User $viewer): array
    {
        $items = [];
        foreach ($this->repoStore->all() as $repo) {
            if (!$repo->isOwnedBy($targetUser)) {
                continue;
            }

            $level = $this->accessResolver->levelFor($viewer, $repo->name(), $repo);
            if (!$level->canRead()) {
                continue;
            }

            if (null === $viewer && $repo->isHidden()) {
                continue;
            }

            $role = null !== $viewer ? $this->roleFor($viewer, $repo) : null;

            $items[] = [
                'name' => $repo->nameString(),
                'description' => $repo->description(),
                'role' => $role ?? 'Public',
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

    private function notFound(): Response
    {
        return new Response(
            "<!DOCTYPE html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"><title>Not Found</title></head><body><p>User not found.</p></body></html>\n",
            Response::HTTP_NOT_FOUND,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
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
}

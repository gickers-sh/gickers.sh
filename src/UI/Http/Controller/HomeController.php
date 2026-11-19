<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Identity\Domain\Model\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Repo\Domain\RepoStore;
use App\Shared\Infrastructure\Config\ServerSettings;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /`: the clean server home page — introduces the Git server, provides SSH connection
 * instructions, web login guidance, and showcases public repositories.
 */
final readonly class HomeController
{
    public function __construct(
        private RepoStore $repoStore,
        private ServerSettings $settings,
        private Security $security,
        private \Twig\Environment $twig,
    ) {}

    #[Route('/', methods: ['GET'])]
    public function __invoke(): Response
    {
        $user = $this->domainUser();
        $sshPrefix = $this->sshPrefix();

        $html = $this->twig->render('home.html.twig', [
            'serverName' => $this->settings->name ?: 'codes.sh',
            'currentUsername' => $user?->username(),
            'sshPrefix' => $sshPrefix,
            'publicRepositories' => $this->publicRepositories(),
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
     * Public repositories (non-private, non-hidden) sorted by name.
     *
     * @return list<array{name: string, description: string, owner: string}>
     */
    private function publicRepositories(): array
    {
        $items = [];
        foreach ($this->repoStore->all() as $repo) {
            if ($repo->isPrivate() || $repo->isHidden()) {
                continue;
            }
            $items[] = [
                'name' => $repo->nameString(),
                'description' => $repo->description(),
                'owner' => $repo->owner()->username(),
            ];
        }

        usort($items, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $items;
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

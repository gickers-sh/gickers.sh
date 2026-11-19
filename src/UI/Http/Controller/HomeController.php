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
 * `GET /`: landing and onboarding page for codes.sh.
 * Highlights SSH onboarding commands (`ssh codes.sh`, `ssh codes.sh signup`),
 * interactive TUI usage, web magic link authentication, and prominent links to documentation and repositories.
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
        $sshSignupCommand = $sshPrefix . ' signup';
        $sshUserExample = $this->sshUserCommand('<username>');
        $sshSignupUserExample = $this->sshUserCommand('<username>', 'signup');

        $html = $this->twig->render('home.html.twig', [
            'serverName' => $this->settings->name ?: 'codes.sh',
            'currentUsername' => $user?->username(),
            'sshPrefix' => $sshPrefix,
            'sshSignupCommand' => $sshSignupCommand,
            'sshUserExample' => $sshUserExample,
            'sshSignupUserExample' => $sshSignupUserExample,
            'publicRepositoriesCount' => $this->publicRepositoriesCount(),
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
     * Counts all public (non-private, non-hidden) repositories.
     */
    private function publicRepositoriesCount(): int
    {
        $count = 0;
        foreach ($this->repoStore->all() as $repo) {
            if (!$repo->isPrivate() && !$repo->isHidden()) {
                ++$count;
            }
        }

        return $count;
    }

    private function domainUser(): ?User
    {
        $securityUser = $this->security->getUser();

        return $securityUser instanceof SecurityUser ? $securityUser->user() : null;
    }

    /**
     * Base SSH invocation advertised for this server (e.g. `ssh codes.sh` or `ssh -p 2222 codes.sh`).
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
     * Constructs user-targeted SSH invocation (e.g. `ssh <username>@codes.sh signup`).
     */
    private function sshUserCommand(string $username, string $subcommand = ''): string
    {
        $raw = trim($this->settings->sshPublicUrl);
        $host = 'codes.sh';
        $port = null;

        if ('' !== $raw) {
            $url = str_contains($raw, '://') ? $raw : 'ssh://' . $raw;
            $parsed = parse_url($url);
            if (false !== $parsed && isset($parsed['host'])) {
                $host = $parsed['host'];
                $port = isset($parsed['port']) && 22 !== (int) $parsed['port'] ? (int) $parsed['port'] : null;
            } else {
                $host = $raw;
            }
        }

        $cmd = 'ssh';
        if (null !== $port) {
            $cmd .= " -p {$port}";
        }
        $cmd .= " {$username}@{$host}";
        if ('' !== $subcommand) {
            $cmd .= " {$subcommand}";
        }

        return $cmd;
    }
}

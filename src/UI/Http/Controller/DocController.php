<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Identity\Domain\Model\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\UI\Ssh\Help\CommandDocumentationRegistry;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /docs` and `GET /help`: Interactive Web CLI documentation.
 * Centralizes all command specifications, synopsis, arguments, flags,
 * and copyable commands for browser users.
 */
final readonly class DocController
{
    public function __construct(
        private CommandDocumentationRegistry $docRegistry,
        private ServerSettings $settings,
        private Security $security,
        private \Twig\Environment $twig,
    ) {}

    #[Route('/docs', methods: ['GET'])]
    #[Route('/help', methods: ['GET'])]
    public function __invoke(): Response
    {
        $user = $this->domainUser();
        $isAdmin = $user?->isAdmin() ?? false;
        $sshPrefix = $this->sshPrefix();

        $commands = $this->docRegistry->allCommands($isAdmin, $sshPrefix);

        $html = $this->twig->render('web/docs.html.twig', [
            'serverName' => $this->settings->name ?: 'codes.sh',
            'currentUsername' => $user?->username(),
            'isAdmin' => $isAdmin,
            'sshPrefix' => $sshPrefix,
            'commands' => $commands,
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

    private function domainUser(): ?User
    {
        $securityUser = $this->security->getUser();

        return $securityUser instanceof SecurityUser ? $securityUser->user() : null;
    }

    private function sshPrefix(): string
    {
        $raw = trim($this->settings->sshPublicUrl);
        if ('' === $raw) {
            return 'ssh codes.sh';
        }

        $parts = parse_url($raw);
        if (!is_array($parts) || !isset($parts['host'])) {
            return 'ssh ' . $raw;
        }

        $out = 'ssh';
        if (isset($parts['port']) && 22 !== (int) $parts['port']) {
            $out .= ' -p ' . (int) $parts['port'];
        }
        if (isset($parts['user']) && '' !== $parts['user']) {
            $out .= ' ' . $parts['user'] . '@' . $parts['host'];
        } else {
            $out .= ' ' . $parts['host'];
        }

        return $out;
    }
}

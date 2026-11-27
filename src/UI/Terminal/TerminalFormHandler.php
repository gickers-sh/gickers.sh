<?php

declare(strict_types=1);

namespace App\UI\Terminal;

use App\Identity\Domain\Model\User;
use App\Shared\Domain\Exception\InvalidInput;
use Termstrap\Termstrap;

/**
 * Reusable handler for interactive Terminal wizards, Gum-styled forms,
 * and TokyoNight-themed ANSI cards.
 */
final class TerminalFormHandler
{
    private ?string $cachedBashLib = null;
    private ?string $cachedPsLib = null;

    public function getBashLibrary(): string
    {
        if (null !== $this->cachedBashLib) {
            return $this->cachedBashLib;
        }

        $path = \dirname(__DIR__, 3) . '/templates/ssh/lib/gum_lib.sh';
        $content = file_get_contents($path);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Failed to load Gum Bash library from %s', $path));
        }

        return $this->cachedBashLib = $content;
    }

    public function getPowerShellLibrary(): string
    {
        if (null !== $this->cachedPsLib) {
            return $this->cachedPsLib;
        }

        $path = \dirname(__DIR__, 3) . '/templates/ssh/lib/gum_lib.ps1';
        $content = file_get_contents($path);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Failed to load Gum PowerShell library from %s', $path));
        }

        return $this->cachedPsLib = $content;
    }

    public function renderHeaderCard(
        string $title,
        string $subtitle,
        ?string $badge = null,
        int $width = 72,
    ): string {
        $safeTitle = htmlspecialchars($title, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $safeSubtitle = htmlspecialchars($subtitle, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $badgeHtml = '';
        if (null !== $badge && '' !== trim($badge)) {
            $safeBadge = htmlspecialchars(trim($badge), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
            $badgeHtml = " <span class=\"text-pink\">[{$safeBadge}]</span>";
        }

        $html = <<<HTML
<div class="border rounded p-1 mb-1" style="background-color: #1f2335;">
  <div class="text-cyan font-bold">{$safeTitle}{$badgeHtml}</div>
  <div class="text-muted">{$safeSubtitle}</div>
</div>
HTML;

        return Termstrap::toAnsi($html, [
            'width' => $width,
            'theme' => 'tokyonight',
        ]);
    }

    /**
     * @param list<string> $bulletPoints
     */
    public function renderSuccessCard(
        string $title,
        string $message,
        array $bulletPoints = [],
        int $width = 72,
    ): string {
        $safeTitle = htmlspecialchars($title, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $safeMsg = htmlspecialchars($message, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        $itemsHtml = '';
        if ([] !== $bulletPoints) {
            $itemsHtml .= "<ul class=\"mb-0\">\n";
            foreach ($bulletPoints as $item) {
                $safeItem = htmlspecialchars($item, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
                $itemsHtml .= "    <li>{$safeItem}</li>\n";
            }
            $itemsHtml .= '  </ul>';
        }

        $html = <<<HTML
<div class="border rounded p-1" style="background-color: #1f2335;">
  <div class="text-success font-bold">&check; {$safeTitle}</div>
  <div class="text-muted">{$safeMsg}</div>
  {$itemsHtml}
</div>
HTML;

        return Termstrap::toAnsi($html, [
            'width' => $width,
            'theme' => 'tokyonight',
        ]);
    }

    public static function validateHost(string $host): string
    {
        $trimmed = trim($host);
        if ('' === $trimmed || 1 !== preg_match('/^[a-zA-Z0-9._:-]{1,253}$/', $trimmed)) {
            throw new InvalidInput('Invalid host configuration.');
        }

        return $trimmed;
    }

    public static function validateUsername(string $username, bool $allowEmpty = false): string
    {
        $trimmed = trim($username);
        if ('' === $trimmed) {
            if ($allowEmpty) {
                return '';
            }
            throw new InvalidInput('Username cannot be empty.');
        }

        if (1 !== preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $trimmed)) {
            throw new InvalidInput('Invalid username: only letters, numbers, dots, hyphens, and underscores are allowed.');
        }

        return User::normalizeUsername($trimmed);
    }

    public static function validatePort(int|string $port): int
    {
        $intPort = (int) $port;
        if ($intPort <= 0 || $intPort > 65535) {
            throw new InvalidInput(sprintf('Invalid port number: %s (must be 1-65535).', (string) $port));
        }

        return $intPort;
    }

    public static function validatePath(?string $path): ?string
    {
        if (null === $path || '' === trim($path)) {
            return null;
        }

        $trimmed = trim($path);
        if (str_starts_with($trimmed, '-') || 1 !== preg_match('/^[a-zA-Z0-9_.\/~:\\\\-]{1,512}$/', $trimmed)) {
            throw new InvalidInput('Invalid path: contains forbidden characters.');
        }

        return $trimmed;
    }

    public static function validateKeyData(string $keyData): string
    {
        $trimmed = trim($keyData);
        if ('' === $trimmed) {
            return '';
        }

        if (1 !== preg_match('/^[A-Za-z0-9+\/=]{1,16384}$/', $trimmed)) {
            throw new InvalidInput('Invalid SSH public key data.');
        }

        return $trimmed;
    }

    public static function validateFingerprint(?string $fingerprint): string
    {
        if (null === $fingerprint || '' === trim($fingerprint)) {
            return '';
        }

        $trimmed = trim($fingerprint);
        if (1 !== preg_match('/^(?:SHA256:[A-Za-z0-9+\/=]+|MD5:[0-9a-fA-F:]+)$/', $trimmed)) {
            throw new InvalidInput('Invalid SSH public key fingerprint.');
        }

        return $trimmed;
    }
}

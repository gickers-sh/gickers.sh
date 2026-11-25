<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Web;

use App\Identity\Infrastructure\WebAuth\WebSessionTokens;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * `web login`: mints a short-lived magic link that logs the caller's browser in.
 *
 * The SSH key already authenticated the user, so no password is involved: we sign
 * a single-purpose login ticket and print the URL to open. Visiting it sets the
 * long-lived `codes_session` cookie, after which repository pages expose the full
 * command palette the user is authorized for.
 */
final class WebLoginCommand extends AbstractSshCommand
{
    public function __construct(
        SshSession $sshSession,
        private readonly WebSessionTokens $tokens,
        private readonly ServerSettings $settings,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment = 'prod',
    ) {
        parent::__construct($sshSession);
    }

    public static function sshName(): string
    {
        return 'web:login';
    }

    protected function configure(): void
    {
        $this->setDescription('Log your browser in and open the web interface');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $user = $this->actor();
        if (null === $user) {
            throw new AccessDenied('unauthorized');
        }

        if ('' === trim($this->settings->httpPublicUrl)) {
            throw new AccessDenied('web login is unavailable: the server has no public HTTP URL configured.');
        }

        $ticket = $this->tokens->issueLoginTicket($user);
        $url = rtrim($this->settings->httpPublicUrl, '/').'/login/'.$ticket;

        // Format the link with OSC 8 to make it directly clickable in modern terminals
        $hyperlink = $this->osc8Link($url, $url);

        if ($this->isDecorated($output)) {
            $this->line($output, \App\UI\Tui\Render\Theme::style('Open this link in your browser to sign in (valid for 10 minutes):', \App\UI\Tui\Render\Theme::BLUE, bold: true));
            $this->line($output);
            $this->line($output, '  '.\App\UI\Tui\Render\Theme::style($hyperlink, \App\UI\Tui\Render\Theme::BLUE, underline: true));
            $this->line($output);
        } else {
            $this->line($output, 'Open this link in your browser to sign in (valid for 10 minutes):');
            $this->line($output);
            $this->line($output, '  '.$hyperlink);
            $this->line($output);
        }

        $copied = $this->promptAndCopy($input, $output, $url);

        if ($copied) {
            $this->line($output);
            $this->line($output, 'Run `'.$this->sshHint().' web logout` to revoke every active browser session.');
        }

        return Command::SUCCESS;
    }

    private function promptAndCopy(InputInterface $input, OutputInterface $output, string $url): bool
    {
        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
        $isTestEnv = 'test' === $this->environment;

        // In test environments without an explicitly provided stream, avoid blocking
        if ($isTestEnv && null === $stream) {
            $this->line($output, 'Press [Enter] to copy to clipboard (any other key to cancel)...');

            return false;
        }

        $stream ??= \defined('STDIN') ? \STDIN : null;
        if (null === $stream || !is_resource($stream) || feof($stream)) {
            return false;
        }

        $this->writeRaw($output, 'Press [Enter] to copy to clipboard (any other key to cancel)...');

        $raw = null;
        if ($isTestEnv) {
            $raw = fgets($stream, 4096);
        } else {
            $r = [$stream];
            $w = [];
            $e = [];
            try {
                // Wait up to 60 seconds for user input
                if (@stream_select($r, $w, $e, 60) > 0) {
                    $raw = fgets($stream, 4096);
                }
            } catch (\Throwable) {
                $raw = fgets($stream, 4096);
            }
        }

        $this->line($output); // Ensure newline after the prompt line

        if (false === $raw || null === $raw) {
            $this->line($output, 'Cancelled.');

            return false;
        }

        // If user pressed Enter, the line contains only newlines (\n, \r\n, \r)
        if ('' === trim($raw, "\r\n")) {
            $this->writeRaw($output, $this->osc52Copy($url));
            $this->line($output, 'Copied to clipboard! Just paste into your browser.');

            return true;
        }

        $this->line($output, 'Cancelled.');

        return false;
    }

    /**
     * OSC 8 terminal hyperlink escape sequence.
     */
    private function osc8Link(string $url, string $text): string
    {
        return "\033]8;;".$url."\033\\".$text."\033]8;;\033\\";
    }

    /**
     * OSC 52 clipboard escape sequence: instructs the user's terminal emulator
     * to copy the given string to the client's local system clipboard.
     */
    private function osc52Copy(string $text): string
    {
        return "\033]52;c;".base64_encode($text)."\007";
    }

    /**
     * Base SSH invocation for this server (e.g. `ssh codes.sh` or `ssh -p 2223 host`),
     * derived from the configured public SSH URL.
     */
    private function sshHint(): string
    {
        $raw = trim($this->settings->sshPublicUrl);
        if ('' === $raw) {
            return 'ssh codes.sh';
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

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
use Symfony\Component\Console\Output\OutputInterface;

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
        $url = rtrim($this->settings->httpPublicUrl, '/') . '/login/' . $ticket;

        $this->line($output, 'Open this link in your browser to sign in (valid for 10 minutes):');
        $this->line($output);
        $this->line($output, '  ' . $url);
        $this->line($output);
        $this->line($output, 'Run `' . $this->sshHint() . ' web logout` to revoke every active browser session.');

        return Command::SUCCESS;
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

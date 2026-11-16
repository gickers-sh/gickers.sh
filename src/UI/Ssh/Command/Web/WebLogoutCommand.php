<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Web;

use App\Identity\Domain\UserStore;
use App\Shared\Domain\Exception\AccessDenied;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `web logout`: revokes every outstanding browser session by bumping the caller's
 * web_token_version, which invalidates all previously issued `codes_session` cookies.
 */
final class WebLogoutCommand extends AbstractSshCommand
{
    public function __construct(
        SshSession $sshSession,
        private readonly UserStore $users,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct($sshSession);
    }

    public static function sshName(): string
    {
        return 'web:logout';
    }

    protected function configure(): void
    {
        $this->setDescription('Sign out of every active browser session');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $user = $this->actor();
        if (null === $user) {
            throw new AccessDenied('unauthorized');
        }

        $user->revokeWebSessions($this->clock->now());
        $this->users->save($user);

        $this->line($output, 'All browser sessions have been signed out.');

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command;

use App\Shared\Domain\Exception\AccessDenied;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class UsernameCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'username';
    }

    public function __construct(
        SshSession $sshSession,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this->setDescription('Show your username');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $actor = $this->actor();
        if (null === $actor) {
            throw new AccessDenied('unauthorized');
        }

        // Deliberate addition: preserved from legacy test / TECH §2.5 pt 4, though absent in Go upstream.
        $this->line($output, sprintf('Username: %s', $actor->username()));

        return Command::SUCCESS;
    }
}

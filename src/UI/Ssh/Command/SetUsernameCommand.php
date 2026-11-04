<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command;

use App\Identity\Application\RenameUser;
use App\Shared\Domain\Exception\AccessDenied;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class SetUsernameCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'set-username';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly RenameUser $renameUser,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Change your username')
            ->addArgument('new_username', InputArgument::REQUIRED, 'New username');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $actor = $this->actor();
        if (null === $actor) {
            throw new AccessDenied('unauthorized');
        }

        $newUsername = $this->stringArgument($input, 'new_username');
        ($this->renameUser)($actor, $actor->username(), $newUsername);

        // Go soft serve renames username silently without stdout/stderr output.
        return Command::SUCCESS;
    }
}

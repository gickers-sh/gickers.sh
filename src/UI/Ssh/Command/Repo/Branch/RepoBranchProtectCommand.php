<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo\Branch;

use App\Repo\Application\ManageBranchProtection;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoBranchProtectCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:branch:protect';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly ManageBranchProtection $manageBranchProtection,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Add or update a branch protection rule')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('pattern', InputArgument::REQUIRED, 'Ref pattern, e.g. main or release/*')
            ->addOption('no-force', null, InputOption::VALUE_NONE, 'Reject non-fast-forward (force) updates')
            ->addOption('no-delete', null, InputOption::VALUE_NONE, 'Reject deletion of matching refs')
            ->addOption('admins-only', null, InputOption::VALUE_NONE, 'Only administrators may update matching refs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->manageBranchProtection->protect(
            actor: $this->actor(),
            name: $this->stringArgument($input, 'repository'),
            pattern: $this->stringArgument($input, 'pattern'),
            noForce: (bool) $input->getOption('no-force'),
            noDelete: (bool) $input->getOption('no-delete'),
            adminsOnly: (bool) $input->getOption('admins-only'),
        );

        return Command::SUCCESS;
    }
}

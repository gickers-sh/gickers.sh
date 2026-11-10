<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo\Branch;

use App\Repo\Application\ManageBranchProtection;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoBranchUnprotectCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:branch:unprotect';
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
            ->setDescription('Remove a branch protection rule')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('pattern', InputArgument::REQUIRED, 'Ref pattern of the rule to remove');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->manageBranchProtection->unprotect(
            actor: $this->actor(),
            name: $this->stringArgument($input, 'repository'),
            pattern: $this->stringArgument($input, 'pattern'),
        );

        return Command::SUCCESS;
    }
}

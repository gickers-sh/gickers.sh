<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo\Deploy;

use App\Deploy\Application\ManageRepoDeployAllow;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoDeployAllowCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:deploy:allow';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly ManageRepoDeployAllow $manageAllow,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Allow or disallow deployments on a repository or specific branch/tag/ref pattern')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('allowed', InputArgument::OPTIONAL, 'Boolean flag (true|false)')
            ->addOption('ref', 'r', InputOption::VALUE_REQUIRED, 'Optional branch, tag, or glob pattern (e.g. main, release/*, v*)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo = $this->stringArgument($input, 'repository');
        $allowedArg = $this->nullableStringArgument($input, 'allowed');
        $rawRef = $input->getOption('ref');
        $ref = \is_string($rawRef) && '' !== trim($rawRef) ? trim($rawRef) : null;

        $allowed = null !== $allowedArg ? filter_var($allowedArg, \FILTER_VALIDATE_BOOLEAN) : null;
        $effective = ($this->manageAllow)($this->actor(), $repo, $allowed, $ref);

        if (null === $allowedArg) {
            $this->line($output, $effective ? 'true' : 'false');
        }

        return Command::SUCCESS;
    }
}

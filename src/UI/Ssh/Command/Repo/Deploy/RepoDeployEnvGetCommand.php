<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo\Deploy;

use App\Deploy\Application\ManageEnvVars;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoDeployEnvGetCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:deploy:env:get';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly ManageEnvVars $manageEnvVars,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Get an environment variable for a deployed branch or tag')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('ref', InputArgument::REQUIRED, 'Branch or tag name')
            ->addArgument('key', InputArgument::REQUIRED, 'Variable name')
            ->addOption('reveal', null, InputOption::VALUE_NONE, 'Reveal the decrypted secret value');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo = $this->stringArgument($input, 'repository');
        $ref = $this->stringArgument($input, 'ref');
        $key = $this->stringArgument($input, 'key');
        $reveal = (bool) $input->getOption('reveal');

        $val = $this->manageEnvVars->get($this->actor(), $repo, $ref, $key, $reveal);
        $this->line($output, $val);

        return Command::SUCCESS;
    }
}

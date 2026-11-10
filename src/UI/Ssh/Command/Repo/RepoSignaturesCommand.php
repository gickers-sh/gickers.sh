<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo;

use App\Repo\Application\ManageBranchProtection;
use App\Repo\Application\Query\RepoQuery;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoSignaturesCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:signatures';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly RepoQuery $repoQuery,
        private readonly ManageBranchProtection $manageBranchProtection,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Get or set whether pushes must carry verified signed commits')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('value', InputArgument::OPTIONAL, 'true or false to require signed commits');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->stringArgument($input, 'repository');
        $value = $this->nullableStringArgument($input, 'value');

        if (null === $value) {
            $repo = $this->repoQuery->get($this->actor(), $name);
            $this->line($output, $repo->requireSignedCommits() ? 'true' : 'false');

            return Command::SUCCESS;
        }

        $require = filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE);
        if (null === $require) {
            throw new InvalidInput(sprintf('invalid boolean value "%s"', $value));
        }

        $this->manageBranchProtection->setRequireSignedCommits($this->actor(), $name, $require);

        return Command::SUCCESS;
    }
}

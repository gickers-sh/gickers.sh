<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\PullRequest;

use App\PullRequest\Application\Query\PullRequestQuery;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class DiffCommand extends Command
{
    public function __construct(
        private readonly SshSession $sshSession,
        private readonly PullRequestQuery $pullRequestQuery,
    ) {
        parent::__construct();
    }

    public static function sshName(): string
    {
        return 'pr:diff';
    }

    protected function configure(): void
    {
        $this
            ->setName(self::sshName())
            ->setDescription('Show diff for a pull request')
            ->setAliases(['prs:diff', 'pull-request:diff', 'pull-requests:diff'])
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('number', InputArgument::REQUIRED, 'Pull request number');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoArg = $input->getArgument('repository');
        $repoName = is_string($repoArg) ? $repoArg : '';
        $numArg = $input->getArgument('number');
        $rawNum = is_string($numArg) ? $numArg : '';
        if (!ctype_digit($rawNum)) {
            throw new InvalidInput(sprintf("invalid pull request number '%s'", $rawNum));
        }
        $number = (int) $rawNum;

        $diff = $this->pullRequestQuery->diff($this->sshSession->user(), $repoName, $number);
        $output->write($diff);

        return Command::SUCCESS;
    }
}

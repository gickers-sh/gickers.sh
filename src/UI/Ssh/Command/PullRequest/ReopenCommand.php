<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\PullRequest;

use App\PullRequest\Application\ReopenPullRequest;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ReopenCommand extends Command
{
    public function __construct(
        private readonly SshSession $sshSession,
        private readonly ReopenPullRequest $reopenPullRequest,
    ) {
        parent::__construct();
    }

    public static function sshName(): string
    {
        return 'pr:reopen';
    }

    protected function configure(): void
    {
        $this
            ->setName(self::sshName())
            ->setDescription('Reopen a closed pull request')
            ->setAliases(['prs:reopen', 'pull-request:reopen', 'pull-requests:reopen'])
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

        ($this->reopenPullRequest)(
            actor: $this->sshSession->user(),
            repo: $repoName,
            number: $number,
        );

        $output->write(sprintf("Reopened pull request #%d\n", $number));

        return Command::SUCCESS;
    }
}

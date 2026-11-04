<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\PullRequest;

use App\PullRequest\Application\DeletePullRequest;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class DeleteCommand extends Command
{
    public function __construct(
        private readonly SshSession $sshSession,
        private readonly DeletePullRequest $deletePullRequest,
    ) {
        parent::__construct();
    }

    public static function sshName(): string
    {
        return 'pr:delete';
    }

    protected function configure(): void
    {
        $this
            ->setName(self::sshName())
            ->setDescription('Permanently delete a pull request')
            ->setAliases(['pr:rm', 'prs:delete', 'prs:rm', 'pull-request:delete', 'pull-requests:delete'])
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

        ($this->deletePullRequest)(
            actor: $this->sshSession->user(),
            repo: $repoName,
            number: $number,
        );

        $output->write(sprintf("Pull request #%d deleted\n", $number));

        return Command::SUCCESS;
    }
}

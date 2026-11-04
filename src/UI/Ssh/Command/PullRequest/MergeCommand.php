<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\PullRequest;

use App\PullRequest\Application\MergePullRequest;
use App\PullRequest\Domain\MergeStrategy;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MergeCommand extends Command
{
    public function __construct(
        private readonly SshSession $sshSession,
        private readonly MergePullRequest $mergePullRequest,
    ) {
        parent::__construct();
    }

    public static function sshName(): string
    {
        return 'pr:merge';
    }

    protected function configure(): void
    {
        $this
            ->setName(self::sshName())
            ->setDescription('Merge a pull request')
            ->setAliases(['prs:merge', 'pull-request:merge', 'pull-requests:merge'])
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('number', InputArgument::REQUIRED, 'Pull request number')
            ->addOption('title', 't', InputOption::VALUE_REQUIRED, 'Custom merge commit title')
            ->addOption('strategy', 's', InputOption::VALUE_REQUIRED, 'Merge strategy (merge, squash, fast-forward)', 'merge');
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

        $titleOpt = $input->getOption('title');
        $titleStr = is_string($titleOpt) && '' !== trim($titleOpt) ? trim($titleOpt) : null;
        $stratOpt = $input->getOption('strategy');
        $strategyStr = is_string($stratOpt) ? $stratOpt : 'merge';
        $strategy = MergeStrategy::fromInput($strategyStr);

        $pr = ($this->mergePullRequest)(
            actor: $this->sshSession->user(),
            repo: $repoName,
            number: $number,
            strategy: $strategy,
            title: $titleStr,
        );

        $shortSha = substr((string) ($pr->mergeCommit() ?? '0000000'), 0, 7);
        $output->write(sprintf("Successfully merged pull request #%d (commit %s)\n", $number, $shortSha));

        return Command::SUCCESS;
    }
}

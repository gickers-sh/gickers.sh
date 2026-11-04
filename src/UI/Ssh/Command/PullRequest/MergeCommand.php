<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\PullRequest;

use App\PullRequest\Application\MergePullRequest;
use App\PullRequest\Domain\MergeStrategy;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MergeCommand extends AbstractSshCommand
{
    public function __construct(
        SshSession $sshSession,
        private readonly MergePullRequest $mergePullRequest,
    ) {
        parent::__construct($sshSession);
    }

    public static function sshName(): string
    {
        return 'pr:merge';
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Merge a pull request')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('number', InputArgument::REQUIRED, 'Pull request number')
            ->addOption('title', 't', InputOption::VALUE_REQUIRED, 'Custom merge commit title')
            ->addOption('strategy', 's', InputOption::VALUE_REQUIRED, 'Merge strategy (merge, squash, fast-forward)', 'merge');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoName = $this->stringArgument($input, 'repository');
        $rawNum = $this->stringArgument($input, 'number');
        if (!ctype_digit($rawNum)) {
            throw new InvalidInput(sprintf("invalid pull request number '%s'", $rawNum));
        }
        $number = (int) $rawNum;

        $titleStr = $this->nullableStringOption($input, 'title');
        $strategyStr = $this->stringOption($input, 'strategy', 'merge');
        $strategy = MergeStrategy::fromInput($strategyStr);

        $pr = ($this->mergePullRequest)(
            actor: $this->actor(),
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

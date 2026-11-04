<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\PullRequest;

use App\PullRequest\Application\OpenPullRequest;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class CreateCommand extends AbstractSshCommand
{
    public function __construct(
        SshSession $sshSession,
        private readonly OpenPullRequest $openPullRequest,
    ) {
        parent::__construct($sshSession);
    }

    public static function sshName(): string
    {
        return 'pr:create';
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Create a new pull request')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addOption('head', 'H', InputOption::VALUE_REQUIRED, 'Source branch to merge from (e.g. "my-feature" or "my-fork:feature")')
            ->addOption('base', 'B', InputOption::VALUE_REQUIRED, 'Target branch to merge into (defaults to default branch)')
            ->addOption('title', 't', InputOption::VALUE_REQUIRED, 'Title of the pull request')
            ->addOption('body', 'b', InputOption::VALUE_REQUIRED, 'Description/body of the pull request', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $user = $this->actor();
        if (null === $user) {
            throw new AccessDenied('unauthorized');
        }

        $repoName = $this->stringArgument($input, 'repository');
        $head = trim($this->stringOption($input, 'head'));
        if ('' === $head) {
            throw new InvalidInput('--head branch must be specified');
        }

        $baseStr = $this->nullableStringOption($input, 'base');
        $titleOpt = $this->nullableStringOption($input, 'title');
        $title = $titleOpt ?? 'Pull Request';
        $body = $this->stringOption($input, 'body');

        $pr = ($this->openPullRequest)(
            actor: $user,
            repo: $repoName,
            title: $title,
            description: $body,
            head: $head,
            base: $baseStr,
        );

        $output->write(sprintf(
            "Created pull request #%d: %s (%s ← %s)\n",
            $pr->number(),
            $pr->title(),
            $pr->baseRef(),
            $pr->headRef(),
        ));

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\PullRequest;

use App\PullRequest\Application\OpenPullRequest;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class CreateCommand extends Command
{
    public function __construct(
        private readonly SshSession $sshSession,
        private readonly OpenPullRequest $openPullRequest,
    ) {
        parent::__construct();
    }

    public static function sshName(): string
    {
        return 'pr:create';
    }

    protected function configure(): void
    {
        $this
            ->setName(self::sshName())
            ->setDescription('Create a new pull request')
            ->setAliases(['prs:create', 'pull-request:create', 'pull-requests:create'])
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addOption('head', 'H', InputOption::VALUE_REQUIRED, 'Source branch to merge from (e.g. "my-feature" or "my-fork:feature")')
            ->addOption('base', 'B', InputOption::VALUE_REQUIRED, 'Target branch to merge into (defaults to default branch)')
            ->addOption('title', 't', InputOption::VALUE_REQUIRED, 'Title of the pull request')
            ->addOption('body', 'b', InputOption::VALUE_REQUIRED, 'Description/body of the pull request', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $user = $this->sshSession->user();
        if (null === $user) {
            throw new AccessDenied('unauthorized');
        }

        $repoArg = $input->getArgument('repository');
        $repoName = is_string($repoArg) ? $repoArg : '';
        $headOpt = $input->getOption('head');
        $head = is_string($headOpt) ? trim($headOpt) : '';
        if ('' === $head) {
            throw new InvalidInput('--head branch must be specified');
        }

        $baseOpt = $input->getOption('base');
        $baseStr = is_string($baseOpt) && '' !== trim($baseOpt) ? trim($baseOpt) : null;
        $titleOpt = $input->getOption('title');
        $title = is_string($titleOpt) && '' !== trim($titleOpt) ? trim($titleOpt) : 'Pull Request';
        $bodyOpt = $input->getOption('body');
        $body = is_string($bodyOpt) ? $bodyOpt : '';

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

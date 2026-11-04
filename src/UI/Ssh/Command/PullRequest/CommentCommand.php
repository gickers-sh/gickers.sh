<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\PullRequest;

use App\PullRequest\Application\CommentPullRequest;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class CommentCommand extends Command
{
    public function __construct(
        private readonly SshSession $sshSession,
        private readonly CommentPullRequest $commentPullRequest,
    ) {
        parent::__construct();
    }

    public static function sshName(): string
    {
        return 'pr:comment';
    }

    protected function configure(): void
    {
        $this
            ->setName(self::sshName())
            ->setDescription('Add a comment to a pull request')
            ->setAliases(['prs:comment', 'pull-request:comment', 'pull-requests:comment'])
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('number', InputArgument::REQUIRED, 'Pull request number')
            ->addArgument('body', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Comment body');
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

        /** @var list<string> $bodyParts */
        $bodyParts = (array) $input->getArgument('body');
        $body = implode(' ', $bodyParts);

        $comment = ($this->commentPullRequest)(
            actor: $this->sshSession->user(),
            repo: $repoName,
            number: $number,
            body: $body,
        );

        $output->write(sprintf("Comment added (ID: %d)\n", $comment->id()));

        return Command::SUCCESS;
    }
}

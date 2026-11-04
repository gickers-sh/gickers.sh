<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\PullRequest;

use App\PullRequest\Application\CommentPullRequest;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class CommentCommand extends AbstractSshCommand
{
    public function __construct(
        SshSession $sshSession,
        private readonly CommentPullRequest $commentPullRequest,
    ) {
        parent::__construct($sshSession);
    }

    public static function sshName(): string
    {
        return 'pr:comment';
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Add a comment to a pull request')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('number', InputArgument::REQUIRED, 'Pull request number')
            ->addArgument('body', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Comment body');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoName = $this->stringArgument($input, 'repository');
        $rawNum = $this->stringArgument($input, 'number');
        if (!ctype_digit($rawNum)) {
            throw new InvalidInput(sprintf("invalid pull request number '%s'", $rawNum));
        }
        $number = (int) $rawNum;

        /** @var list<string> $bodyParts */
        $bodyParts = (array) $input->getArgument('body');
        $body = implode(' ', $bodyParts);

        $comment = ($this->commentPullRequest)(
            actor: $this->actor(),
            repo: $repoName,
            number: $number,
            body: $body,
        );

        $output->write(sprintf("Comment added (ID: %d)\n", $comment->id()));

        return Command::SUCCESS;
    }
}

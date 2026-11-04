<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\PullRequest;

use App\PullRequest\Application\ClosePullRequest;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class CloseCommand extends AbstractSshCommand
{
    public function __construct(
        SshSession $sshSession,
        private readonly ClosePullRequest $closePullRequest,
    ) {
        parent::__construct($sshSession);
    }

    public static function sshName(): string
    {
        return 'pr:close';
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Close a pull request without merging')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('number', InputArgument::REQUIRED, 'Pull request number');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoName = $this->stringArgument($input, 'repository');
        $rawNum = $this->stringArgument($input, 'number');
        if (!ctype_digit($rawNum)) {
            throw new InvalidInput(sprintf("invalid pull request number '%s'", $rawNum));
        }
        $number = (int) $rawNum;

        ($this->closePullRequest)(
            actor: $this->actor(),
            repo: $repoName,
            number: $number,
        );

        $output->write(sprintf("Closed pull request #%d\n", $number));

        return Command::SUCCESS;
    }
}

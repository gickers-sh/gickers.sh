<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo\Tag;

use App\Repo\Application\DeleteTag;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoTagDeleteCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:tag:delete';
    }

    public static function sshAliases(): array
    {
        return ['repo:tag:del', 'repo:tag:remove', 'repo:tag:rm'];
    }

    public function __construct(
        SshSession $sshSession,
        private readonly DeleteTag $deleteTag,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Delete a tag from a repository')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('tag', InputArgument::REQUIRED, 'Tag name to delete');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->stringArgument($input, 'repository');
        $tag = $this->stringArgument($input, 'tag');

        ($this->deleteTag)($this->actor(), $name, $tag);

        // Go soft serve deletes tags silently without stdout/stderr output.
        return Command::SUCCESS;
    }
}

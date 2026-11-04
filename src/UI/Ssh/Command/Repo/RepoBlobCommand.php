<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo;

use App\Repo\Application\Query\RepoQuery;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoBlobCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:blob';
    }

    public static function sshAliases(): array
    {
        return ['repo:cat', 'repo:show'];
    }

    public function __construct(
        SshSession $sshSession,
        private readonly RepoQuery $repoQuery,
        private readonly BareRepositoryStorage $storage,
        private readonly GitRepositoryReader $gitReader,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Display contents of a file at path')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('ref_or_path', InputArgument::OPTIONAL, 'Git reference or file path if no ref')
            ->addArgument('path', InputArgument::OPTIONAL, 'File path when ref is specified')
            ->addOption('raw', 'r', InputOption::VALUE_NONE, 'Print raw file contents without binary check');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->stringArgument($input, 'repository');
        $arg1 = $this->nullableStringArgument($input, 'ref_or_path');
        $arg2 = $this->nullableStringArgument($input, 'path');
        $raw = (bool) $input->getOption('raw');

        if (null === $arg1) {
            throw new InvalidInput('Missing file path');
        }

        if (null !== $arg2 && '' !== trim($arg2)) {
            $ref = $arg1;
            $path = $arg2;
        } else {
            $ref = 'HEAD';
            $path = $arg1;
        }

        $repo = $this->repoQuery->get($this->actor(), $name);
        $gitRepo = $this->storage->open($repo->name());

        $content = $this->gitReader->getBlob($gitRepo, $ref, $path);

        if (!$raw && str_contains($content, "\0")) {
            $this->line($output, 'binary file: use --raw to print');

            return Command::SUCCESS;
        }

        $this->writeRaw($output, $content);

        return Command::SUCCESS;
    }
}

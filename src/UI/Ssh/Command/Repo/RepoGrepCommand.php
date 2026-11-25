<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo;

use App\Repo\Application\Query\RepoQuery;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Shared\Domain\Exception\NotFound;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoGrepCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:grep';
    }

    public static function sshAliases(): array
    {
        return ['repo:search'];
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
            ->setDescription('Search repository contents')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('pattern', InputArgument::REQUIRED, 'Pattern to search for (git grep regular expression)')
            ->addArgument('reference', InputArgument::OPTIONAL, 'Git reference (branch, tag, or commit SHA)', 'HEAD');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->stringArgument($input, 'repository');
        $pattern = $this->stringArgument($input, 'pattern');
        $ref = $this->stringArgument($input, 'reference', 'HEAD');

        $repo = $this->repoQuery->get($this->actor(), $name);
        $gitRepo = $this->storage->open($repo->name());

        $matches = $this->gitReader->grep($gitRepo, $pattern, $ref);
        if (null === $matches) {
            throw new NotFound('reference not found');
        }

        foreach ($matches as $match) {
            if ($this->isDecorated($output)) {
                $parts = explode(':', $match, 4);
                if (4 === count($parts)) {
                    [$mRef, $mPath, $mLine, $mContent] = $parts;
                    $refStyled = \App\UI\Tui\Render\Theme::style($mRef, \App\UI\Tui\Render\Theme::HASH, bold: true);
                    $pathStyled = \App\UI\Tui\Render\Theme::style($mPath, \App\UI\Tui\Render\Theme::CYAN, bold: true);
                    $lineStyled = \App\UI\Tui\Render\Theme::style($mLine, \App\UI\Tui\Render\Theme::ORANGE);
                    $sep = \App\UI\Tui\Render\Theme::style(':', \App\UI\Tui\Render\Theme::MUTED);
                    $this->line($output, sprintf('%s%s%s%s%s%s%s', $refStyled, $sep, $pathStyled, $sep, $lineStyled, $sep, $mContent));
                } else {
                    $this->line($output, $match);
                }
            } else {
                $this->line($output, $match);
            }
        }

        return Command::SUCCESS;
    }
}

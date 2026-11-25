<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo;

use App\Repo\Application\Query\RepoQuery;
use App\Repo\Domain\Git\TreeEntry;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoTreeCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:tree';
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
            ->setDescription('Print repository tree at path')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('reference', InputArgument::OPTIONAL, 'Git reference (branch, tag, or commit SHA)', 'HEAD')
            ->addArgument('path', InputArgument::OPTIONAL, 'Directory subpath', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->stringArgument($input, 'repository');
        $ref = $this->stringArgument($input, 'reference', 'HEAD');
        $path = $this->stringArgument($input, 'path', '');

        $repo = $this->repoQuery->get($this->actor(), $name);
        $gitRepo = $this->storage->open($repo->name());

        $entries = $this->gitReader->getTree($gitRepo, $ref, $path);

        // Sort: directories first, then alphabetical
        usort($entries, static function (TreeEntry $a, TreeEntry $b): int {
            if ($a->isDirectory() !== $b->isDirectory()) {
                return $a->isDirectory() ? -1 : 1;
            }

            return strcmp($a->name, $b->name);
        });

        foreach ($entries as $entry) {
            $sizeStr = (null === $entry->size || 0 === $entry->size) ? '-' : $this->humanizeBytes($entry->size);
            if ($this->isDecorated($output)) {
                $modeStyled = \App\UI\Tui\Render\Theme::style($entry->mode, \App\UI\Tui\Render\Theme::MUTED);
                $sizeStyled = \App\UI\Tui\Render\Theme::style($sizeStr, \App\UI\Tui\Render\Theme::ORANGE);
                $nameStyled = $entry->isDirectory()
                    ? \App\UI\Tui\Render\Theme::style($entry->name . '/', \App\UI\Tui\Render\Theme::CYAN, bold: true)
                    : \App\UI\Tui\Render\Theme::style($entry->name, \App\UI\Tui\Render\Theme::FG);
                $this->line($output, sprintf("%s\t%s\t %s", $modeStyled, $sizeStyled, $nameStyled));
            } else {
                // Go format: cmd.Printf("%s\t%s\t %s\n", ent.Mode(), ssize, ent.Name())
                $this->line($output, sprintf("%s\t%s\t %s", $entry->mode, $sizeStr, $entry->name));
            }
        }

        return Command::SUCCESS;
    }

    private function humanizeBytes(int $bytes): string
    {
        if ($bytes < 1000) {
            return $bytes.' B';
        }
        $units = ['kB', 'MB', 'GB', 'TB'];
        $val = (float) $bytes;
        $unitIdx = 0;
        while ($val >= 1000.0 && $unitIdx < count($units) - 1) {
            $val /= 1000.0;
            ++$unitIdx;
        }

        return sprintf('%.1f %s', $val, $units[$unitIdx]);
    }
}

<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo;

use App\Repo\Application\Query\RepoQuery;
use App\Repo\Application\RepoStatsService;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoInfoCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:info';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly RepoQuery $repoQuery,
        private readonly BareRepositoryStorage $storage,
        private readonly GitRepositoryReader $gitReader,
        private readonly RepoStatsService $stats,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Display repository information')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->stringArgument($input, 'repository');
        $repo = $this->repoQuery->get($this->actor(), $name);

        $branches = [];
        $tags = [];
        $defaultBranch = 'main';

        if ($this->storage->exists($repo->name())) {
            $gitRepo = $this->storage->open($repo->name());
            try {
                $refs = $this->gitReader->getRefs($gitRepo);
                $branches = $refs->branches;
                $tags = $refs->tags;
                $defaultBranch = $refs->currentBranch;
            } catch (\Throwable) {
                // Repository might be bare and empty before any push
            }
        }

        // Mirror Go soft serve formatting exactly (gickers.sh.src/pkg/ssh/cmd/repo.go:81-106)
        $this->line($output, trim($this->formatKeyValue($output, 'Project Name', $repo->projectName(), \App\UI\Tui\Render\Theme::FG, true)));
        $this->line($output, $this->formatKeyValue($output, 'Repository', $repo->nameString(), \App\UI\Tui\Render\Theme::CYAN, true));
        $this->line($output, trim($this->formatKeyValue($output, 'Description', $repo->description(), \App\UI\Tui\Render\Theme::FG_DIM)));
        $this->line($output, $this->formatKeyValue($output, 'Private', $repo->isPrivate()));
        $this->line($output, $this->formatKeyValue($output, 'Hidden', $repo->isHidden()));
        $this->line($output, $this->formatKeyValue($output, 'Mirror', $repo->isMirror()));

        $forkParent = $repo->forkOf();
        if (null !== $forkParent) {
            $this->line($output, $this->formatKeyValue($output, 'Fork of', $forkParent->nameString(), \App\UI\Tui\Render\Theme::CYAN));
        }

        $owner = $repo->owner();
        $this->line($output, trim($this->formatKeyValue($output, 'Owner', $owner->username(), \App\UI\Tui\Render\Theme::CYAN, true)));
        $this->line($output, $this->formatKeyValue($output, 'Default Branch', $defaultBranch, \App\UI\Tui\Render\Theme::HASH, true));

        $stats = $this->stats->forRepo($repo);
        $this->line($output, $this->formatKeyValue($output, 'Clones', $stats->clones, \App\UI\Tui\Render\Theme::ORANGE));
        $this->line($output, $this->formatKeyValue($output, 'Fetches', $stats->fetches, \App\UI\Tui\Render\Theme::ORANGE));
        if (null !== $stats->diskUsageBytes) {
            $this->line($output, $this->formatKeyValue($output, 'Disk Usage', self::humanBytes($stats->diskUsageBytes), \App\UI\Tui\Render\Theme::ORANGE));
        }

        if (count($branches) > 0) {
            $this->line($output, $this->formatHeader($output, 'Branches:'));
            foreach ($branches as $b) {
                if ($this->isDecorated($output)) {
                    $bStyle = $b === $defaultBranch
                        ? \App\UI\Tui\Render\Theme::style('* ' . $b, \App\UI\Tui\Render\Theme::GREEN, bold: true)
                        : \App\UI\Tui\Render\Theme::style('  ' . $b, \App\UI\Tui\Render\Theme::CYAN);
                    $this->line($output, '  ' . $bStyle);
                } else {
                    $this->line($output, sprintf('  - %s', $b));
                }
            }
        }

        if (count($tags) > 0) {
            $this->line($output, $this->formatHeader($output, 'Tags:'));
            foreach ($tags as $t) {
                if ($this->isDecorated($output)) {
                    $this->line($output, '  ' . \App\UI\Tui\Render\Theme::style('- ' . $t, \App\UI\Tui\Render\Theme::ORANGE, bold: true));
                } else {
                    $this->line($output, sprintf('  - %s', $t));
                }
            }
        }

        return Command::SUCCESS;
    }

    private static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024.0 && $unit < \count($units) - 1) {
            $value /= 1024.0;
            ++$unit;
        }

        return 0 === $unit
            ? sprintf('%d %s', $bytes, $units[0])
            : sprintf('%.1f %s', $value, $units[$unit]);
    }
}

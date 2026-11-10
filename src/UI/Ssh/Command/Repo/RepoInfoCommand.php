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

        // Mirror Go soft serve formatting exactly (codes.sh.src/pkg/ssh/cmd/repo.go:81-106)
        $this->line($output, trim(sprintf('Project Name: %s', $repo->projectName())));
        $this->line($output, sprintf('Repository: %s', $repo->nameString()));
        $this->line($output, trim(sprintf('Description: %s', $repo->description())));
        $this->line($output, sprintf('Private: %s', $repo->isPrivate() ? 'true' : 'false'));
        $this->line($output, sprintf('Hidden: %s', $repo->isHidden() ? 'true' : 'false'));
        $this->line($output, sprintf('Mirror: %s', $repo->isMirror() ? 'true' : 'false'));

        $forkParent = $repo->forkOf();
        if (null !== $forkParent) {
            $this->line($output, sprintf('Fork of: %s', $forkParent->nameString()));
        }

        $owner = $repo->owner();
        $this->line($output, trim(sprintf('Owner: %s', $owner->username())));
        $this->line($output, sprintf('Default Branch: %s', $defaultBranch));

        $stats = $this->stats->forRepo($repo);
        $this->line($output, sprintf('Clones: %d', $stats->clones));
        $this->line($output, sprintf('Fetches: %d', $stats->fetches));
        if (null !== $stats->diskUsageBytes) {
            $this->line($output, sprintf('Disk Usage: %s', self::humanBytes($stats->diskUsageBytes)));
        }

        if (count($branches) > 0) {
            $this->line($output, 'Branches:');
            foreach ($branches as $b) {
                $this->line($output, sprintf('  - %s', $b));
            }
        }

        if (count($tags) > 0) {
            $this->line($output, 'Tags:');
            foreach ($tags as $t) {
                $this->line($output, sprintf('  - %s', $t));
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

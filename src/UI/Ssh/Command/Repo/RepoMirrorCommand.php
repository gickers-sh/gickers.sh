<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo;

use App\Access\Application\AccessGuard;
use App\Access\Domain\AccessLevel;
use App\Repo\Application\Query\RepoQuery;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Shared\Domain\Exception\NotFound;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoMirrorCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:mirror';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly RepoQuery $repoQuery,
        private readonly RepoStore $repoStore,
        private readonly AccessGuard $accessGuard,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Set or get a repository mirror property')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('mirror', InputArgument::OPTIONAL, 'Boolean value (true|false)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->stringArgument($input, 'repository');
        $mirrorArg = $this->nullableStringArgument($input, 'mirror');

        if (null === $mirrorArg) {
            $repo = $this->repoQuery->get($this->actor(), $name);
            $this->line($output, $this->formatBoolean($output, $repo->isMirror()));

            return Command::SUCCESS;
        }

        $repo = $this->accessGuard->assertRepo($this->actor(), RepoName::fromInput($name), AccessLevel::ReadWriteAccess);
        if (null === $repo) {
            throw new NotFound('repository not found');
        }

        $isMirror = filter_var($mirrorArg, \FILTER_VALIDATE_BOOLEAN);
        $repo->setMirror($isMirror, $this->clock->now());
        $this->repoStore->save($repo);

        // Go soft serve updates mirror property silently without stdout/stderr output.
        return Command::SUCCESS;
    }
}

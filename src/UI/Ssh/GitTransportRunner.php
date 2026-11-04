<?php

declare(strict_types=1);

namespace App\UI\Ssh;

use App\Access\Application\AccessGuard;
use App\Access\Application\AccessResolver;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\Repo\Application\CreateRepo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Domain\Exception\NotFound;
use App\Shared\Infrastructure\Config\ServerSettings;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

final readonly class GitTransportRunner
{
    public function __construct(
        private AccessGuard $accessGuard,
        private AccessResolver $accessResolver,
        private CreateRepo $createRepo,
        private BareRepositoryStorage $storage,
        private RepoStore $repoStore,
        private ServerSettings $serverSettings,
        private SshSession $sshSession,
    ) {
    }

    public function run(
        string $command,
        string $rawRepo,
        ?User $actor,
        OutputInterface $output,
        OutputInterface $errOutput,
    ): int {
        if (!in_array($command, ['git-upload-pack', 'git-receive-pack', 'git-upload-archive'], true)) {
            throw new InvalidInput(sprintf('Unsupported git command "%s"', $command));
        }

        if (str_contains($rawRepo, '..') || str_contains($rawRepo, '\\')) {
            throw new InvalidInput('Path traversal detected in repository name');
        }

        $repoName = RepoName::fromInput($rawRepo);

        if ('git-receive-pack' === $command) {
            $existing = $this->repoStore->byName($repoName);
            if (null === $existing) {
                $level = $this->accessResolver->levelFor($actor, $repoName);
                if ($level->canWrite() && null !== $actor) {
                    // Push-to-create
                    ($this->createRepo)($actor, $repoName);
                } else {
                    $this->accessGuard->assertRepo($actor, $repoName, AccessLevel::ReadWriteAccess);
                }
            } else {
                $this->accessGuard->assertRepo($actor, $repoName, AccessLevel::ReadWriteAccess);
            }
        } else {
            $this->accessGuard->assertRepo($actor, $repoName, AccessLevel::ReadOnlyAccess);
        }

        $repoPath = $this->storage->pathFor($repoName);
        if (!is_dir($repoPath)) {
            throw new NotFound(sprintf("repository '%s' not found", $repoName));
        }

        $process = new Process([$command, $repoPath]);
        $env = [
            'SOFT_SERVE_REPO_NAME' => $repoName->value,
            'SOFT_SERVE_USERNAME' => $actor?->username() ?? '',
            'SOFT_SERVE_PUBLIC_KEY' => $this->sshSession->publicKeyFingerprint() ?? '',
            'SOFT_SERVE_DATA_PATH' => $this->serverSettings->dataPath,
        ];
        $process->setEnv($env);
        $process->setInput(\STDIN);
        // Like Go's git transport: no wall-clock limit, large pushes/clones may run for minutes.
        $process->setTimeout(null);

        $process->run(static function (string $type, string $buffer) use ($output, $errOutput): void {
            if (Process::ERR === $type) {
                $errOutput->write($buffer, false, OutputInterface::OUTPUT_RAW);
            } else {
                $output->write($buffer, false, OutputInterface::OUTPUT_RAW);
            }
        });

        return $process->getExitCode() ?? 0;
    }
}

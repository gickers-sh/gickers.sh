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
use App\Shared\Infrastructure\Process\MinimalProcessEnv;
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

    /**
     * @param resource|string|\Traversable<string>|null $inputStream
     */
    public function run(
        string $command,
        string $rawRepo,
        ?User $actor,
        OutputInterface $output,
        OutputInterface $errOutput,
        $inputStream = null,
    ): int {
        if (!in_array($command, ['git-upload-pack', 'git-receive-pack', 'git-upload-archive'], true)) {
            throw new InvalidInput(sprintf('Unsupported git command "%s"', $command));
        }

        if (str_contains($rawRepo, '..') || str_contains($rawRepo, '\\')) {
            throw new InvalidInput('Path traversal detected in repository name');
        }

        $repoName = RepoName::fromInput($rawRepo);
        if (null !== $actor && !str_contains($repoName->value, '/') && null === $this->repoStore->byName($repoName)) {
            $scopedName = RepoName::fromInput($actor->username() . '/' . $repoName->value);
            if (null !== $this->repoStore->byName($scopedName)) {
                $repoName = $scopedName;
            }
        }

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
        $softEnv = [
            'SOFT_SERVE_REPO_NAME' => $repoName->value,
            'SOFT_SERVE_USERNAME' => $actor?->username() ?? '',
            'SOFT_SERVE_PUBLIC_KEY' => $this->sshSession->publicKeyFingerprint() ?? '',
            'SOFT_SERVE_DATA_PATH' => $this->serverSettings->dataPath,
        ];
        if ('git-receive-pack' === $command) {
            // git-receive-pack invokes server hooks (`bin/console soft:hook:*`), which need DB/Symfony config,
            // while still stripping unrelated host secrets (DOCKER_HOST, SOFT_SERVE_INITIAL_ADMIN_KEYS, tokens).
            $env = MinimalProcessEnv::build($softEnv, [
                'PATH',
                'HOME',
                'TMPDIR',
                'TZ',
                'LANG',
                'LC_ALL',
                'APP_ENV',
                'APP_DEBUG',
                'APP_SECRET',
                'DATABASE_URL',
                'MESSENGER_TRANSPORT_DSN',
                'SOFT_SERVE_NAME',
                'SOFT_SERVE_HTTP_PUBLIC_URL',
                'SOFT_SERVE_SSH_PUBLIC_URL',
                'SOFT_SERVE_ANON_ACCESS',
                'SOFT_SERVE_ALLOW_KEYLESS',
                'SOFT_SERVE_LFS_ENABLED',
                'SOFT_SERVE_WEBHOOK_ALLOW_LIST',
                'DEPLOY_GATEWAY_HOST',
                'DEPLOY_DOCKER_RUNTIME',
                'CADDY_ADMIN_ADDR',
            ]);
        } else {
            $env = MinimalProcessEnv::build($softEnv);
        }
        $process->setEnv($env);
        if (null !== $inputStream) {
            $process->setInput($inputStream);
        } elseif (\defined('STDIN')) {
            $process->setInput(\STDIN);
        }
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

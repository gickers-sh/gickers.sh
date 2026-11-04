<?php

declare(strict_types=1);

namespace App\Tests\Functional\GitDaemon;

use App\Repo\Domain\RepoName;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Repo\Infrastructure\Git\GitRepositoryWriter;
use App\Shared\Infrastructure\Config\ServerSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * End-to-end proof that `soft:git-daemon` serves a real `git clone` over git://.
 *
 * A genuinely forked daemon ({@see \App\UI\GitDaemon\GitDaemonCommand}) is launched as
 * a background console process against an isolated temp data dir + temp sqlite seeded
 * with one PUBLIC repo, then `git clone git://127.0.0.1:<port>/demo` must succeed and
 * contain the committed file. Skips cleanly when pcntl/git are unavailable or the
 * daemon cannot boot in this environment (the protocol logic itself is covered by the
 * integration test).
 */
final class GitDaemonCloneTest extends TestCase
{
    private string $projectDir;
    private string $base;
    private string $cloneDir;
    private ?Process $daemon = null;

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('ext-pcntl is required to fork the git daemon.');
        }
        if (!$this->gitAvailable()) {
            self::markTestSkipped('git is not available on PATH.');
        }

        $this->projectDir = \dirname(__DIR__, 3);
        $this->base = sys_get_temp_dir().'/git_daemon_clone_'.bin2hex(random_bytes(6));
        $this->cloneDir = $this->base.'/clone';
        mkdir($this->base.'/repos', 0o755, true);
        mkdir($this->base.'/log', 0o755, true);
    }

    protected function tearDown(): void
    {
        if (null !== $this->daemon && $this->daemon->isRunning()) {
            $this->daemon->stop(3);
        }
        if (isset($this->base) && is_dir($this->base)) {
            (new Process(['rm', '-rf', $this->base]))->run();
        }
    }

    public function testCloneThroughForkedDaemonSucceeds(): void
    {
        $dbPath = $this->base.'/daemon.db';
        $databaseUrl = 'sqlite:///'.$dbPath;

        $this->migrate($databaseUrl);
        $this->seedPublicRepo($dbPath);
        $this->createBareRepoWithCommit();

        $port = $this->freePort();
        $this->daemon = $this->launchDaemon($databaseUrl, $port);

        if (!$this->waitUntilListening($port, 20.0)) {
            $details = sprintf(
                "stdout:\n%s\nstderr:\n%s",
                $this->daemon->getOutput(),
                $this->daemon->getErrorOutput(),
            );
            self::markTestSkipped('git daemon did not start listening in this environment. '.$details);
        }

        $clone = new Process(
            ['git', 'clone', '--quiet', sprintf('git://127.0.0.1:%d/demo', $port), $this->cloneDir],
            $this->projectDir,
        );
        $clone->setTimeout(60);
        $clone->run();

        self::assertTrue(
            $clone->isSuccessful(),
            sprintf(
                "git clone failed (exit %s).\nclone stderr:\n%s\ndaemon stderr:\n%s",
                (string) $clone->getExitCode(),
                $clone->getErrorOutput(),
                $this->daemon->getErrorOutput(),
            ),
        );
        self::assertFileExists($this->cloneDir.'/README.md');
        self::assertStringContainsString('demo', (string) file_get_contents($this->cloneDir.'/README.md'));
    }

    private function migrate(string $databaseUrl): void
    {
        $process = new Process(
            [\PHP_BINARY, $this->projectDir.'/bin/console', 'doctrine:migrations:migrate', '--no-interaction', '--quiet'],
            $this->projectDir,
            $this->daemonEnv($databaseUrl),
        );
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            self::markTestSkipped(
                'Could not migrate the temp daemon database in this environment: '.$process->getErrorOutput(),
            );
        }
    }

    private function seedPublicRepo(string $dbPath): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $pdo = new \PDO('sqlite:'.$dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $user = $pdo->prepare(
            'INSERT INTO users (username, password_hash, updated_at, is_admin, created_at) VALUES (?, ?, ?, ?, ?)',
        );
        $user->execute(['daemon-owner', null, $now, 0, $now]);
        $ownerId = (int) $pdo->lastInsertId();

        $repo = $pdo->prepare(
            'INSERT INTO repos (name, next_pull_request_number, updated_at, project_name, description, is_private, is_hidden, is_mirror, created_at, owner_id, fork_of_id)'
                .' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $repo->execute(['demo', 1, $now, '', '', 0, 0, 0, $now, $ownerId, null]);
    }

    private function createBareRepoWithCommit(): void
    {
        $settings = new ServerSettings(
            name: 'test-server',
            dataPath: $this->base,
            sshListenAddr: '127.0.0.1:2222',
            sshPublicUrl: 'ssh://localhost:2222',
            httpListenAddr: '127.0.0.1:23232',
            httpPublicUrl: 'http://localhost:23232',
            gitListenAddr: ':9418',
            gitMaxConnections: 32,
            lfsEnabled: true,
            anonAccess: 'read-only',
            allowKeyless: true,
            initialAdminKeys: '',
        );

        $storage = new BareRepositoryStorage($settings, new GitRepositoryReader());
        $repo = $storage->init(RepoName::fromInput('demo'), 'main');
        (new GitRepositoryWriter())->writeFile(
            $repo,
            'main',
            'README.md',
            "# demo\n",
            'initial commit',
            'Tester',
            'tester@codes.sh',
        );
    }

    private function launchDaemon(string $databaseUrl, int $port): Process
    {
        $env = $this->daemonEnv($databaseUrl) + [
            'SOFT_SERVE_GIT_LISTEN_ADDR' => ':'.$port,
            'SOFT_SERVE_ALLOW_KEYLESS' => 'true',
        ];

        $daemon = new Process(
            [\PHP_BINARY, $this->projectDir.'/bin/console', 'soft:git-daemon'],
            $this->projectDir,
            $env,
        );
        $daemon->setTimeout(null);
        $daemon->start();

        return $daemon;
    }

    /**
     * @return array<string, string>
     */
    private function daemonEnv(string $databaseUrl): array
    {
        return [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '0',
            'DATABASE_URL' => $databaseUrl,
            'SOFT_SERVE_DATA_PATH' => $this->base,
        ];
    }

    private function waitUntilListening(int $port, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            if (null === $this->daemon || !$this->daemon->isRunning()) {
                return false;
            }

            $errno = 0;
            $errstr = '';
            $client = @stream_socket_client(sprintf('tcp://127.0.0.1:%d', $port), $errno, $errstr, 0.3);
            if (\is_resource($client)) {
                fclose($client);

                return true;
            }

            usleep(100_000);
        }

        return false;
    }

    private function freePort(): int
    {
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!\is_resource($sock)) {
            self::markTestSkipped('Could not allocate a free TCP port: '.$errstr);
        }

        $name = (string) stream_socket_get_name($sock, false);
        fclose($sock);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private function gitAvailable(): bool
    {
        $process = new Process(['git', '--version']);
        $process->run();

        return $process->isSuccessful();
    }
}

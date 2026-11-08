<?php

declare(strict_types=1);

namespace App\Tests\Functional\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * `soft admin migrate|rollback` and `soft serve --dry-run` as an operator runs them:
 * through `bin/soft`, on a throwaway database and data directory.
 */
final class SoftBinaryTest extends TestCase
{
    private string $work;

    protected function setUp(): void
    {
        $this->work = sys_get_temp_dir() . '/codes-sh-bin-' . bin2hex(random_bytes(4));
        mkdir($this->work, 0o700, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->work);
    }

    public function testPruneMigrationRollbackIsIrreversible(): void
    {
        $this->soft(['admin', 'migrate']);
        self::assertContains('repos', $this->tables());
        self::assertContains('users', $this->tables());
        self::assertContains('webhooks', $this->tables());

        try {
            $this->soft(['admin', 'rollback']);
            self::fail('Rolling back the destructive prune migration must be rejected.');
        } catch (\Symfony\Component\Process\Exception\ProcessFailedException $e) {
            self::assertStringContainsString('Removed pull request data cannot be restored.', $e->getProcess()->getErrorOutput());
        }

        self::assertContains('repos', $this->tables());
        self::assertContains('webhooks', $this->tables());
    }

    public function testServeDryRunPreparesSshdForForcedCommandsWithoutStartingAnything(): void
    {
        if (!is_executable('/usr/sbin/sshd') && null === (new \Symfony\Component\Process\ExecutableFinder())->find('sshd')) {
            self::markTestSkipped('sshd is not installed');
        }

        // OpenSSH wire format of a fresh Ed25519 public key.
        $blob = pack('N', 11) . 'ssh-ed25519' . pack('N', 32) . sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        $keyData = base64_encode($blob);
        $output = $this->soft(['serve', '--dry-run', '-v'], [
            'SOFT_SERVE_INITIAL_ADMIN_KEYS' => 'ssh-ed25519 ' . $keyData . ' admin@test',
            'SOFT_SERVE_SSH_LISTEN_ADDR' => '127.0.0.1:0',
        ]);

        self::assertStringContainsString('Dry run: nothing started.', $output);
        foreach (['sshd', 'http', 'worker'] as $process) {
            self::assertMatchesRegularExpression('/^\s+' . $process . '\s+\S/m', $output, $process . ' is planned');
        }

        // sshd does not pass its environment to forced-command sessions: everything
        // `bin/console soft:ssh:dispatch` needs must be in sshd_config as SetEnv.
        $sshdConfig = (string) file_get_contents($this->work . '/data/ssh/sshd_config');
        self::assertMatchesRegularExpression('/^SetEnv .*"DATABASE_URL=sqlite:\/\/\/' . preg_quote($this->work, '/') . '\/db\.sqlite"/m', $sshdConfig);
        self::assertMatchesRegularExpression('/^SetEnv .*"SOFT_SERVE_DATA_PATH=' . preg_quote($this->work, '/') . '\/data"/m', $sshdConfig);

        $authorizedKeys = (string) file_get_contents($this->work . '/data/ssh/authorized_keys');
        self::assertStringContainsString('soft:ssh:dispatch', $authorizedKeys);
        self::assertStringContainsString($keyData, $authorizedKeys);
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $env
     */
    private function soft(array $args, array $env = []): string
    {
        $process = new Process([\PHP_BINARY, \dirname(__DIR__, 3) . '/bin/soft', ...$args], null, [
            'APP_ENV' => 'test',
            'DATABASE_URL' => 'sqlite:///' . $this->work . '/db.sqlite',
            'SOFT_SERVE_DATA_PATH' => $this->work . '/data',
            ...$env,
        ]);
        $process->setTimeout(120);
        $process->mustRun();

        return $process->getOutput() . $process->getErrorOutput();
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        $pdo = new \PDO('sqlite:' . $this->work . '/db.sqlite');
        $rows = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        self::assertNotFalse($rows);

        $tables = [];
        foreach ($rows->fetchAll(\PDO::FETCH_COLUMN) as $name) {
            self::assertIsString($name);
            $tables[] = $name;
        }

        return $tables;
    }
}

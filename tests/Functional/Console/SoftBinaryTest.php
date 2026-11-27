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
        $this->work = sys_get_temp_dir().'/gickers-sh-bin-'.bin2hex(random_bytes(4));
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

        // Roll back one migration at a time: reversible migrations above the prune
        // migration unwind cleanly, but the step that would revert the destructive
        // prune migration (20261108000000) must be rejected as irreversible.
        $rejected = false;
        for ($i = 0; $i < 20 && !$rejected; ++$i) {
            try {
                $this->soft(['admin', 'rollback']);
            } catch (\Symfony\Component\Process\Exception\ProcessFailedException $e) {
                self::assertStringContainsString('Removed pull request data cannot be restored.', $e->getProcess()->getErrorOutput());
                $rejected = true;
            }
        }
        self::assertTrue($rejected, 'Rolling back through the destructive prune migration must be rejected.');

        self::assertContains('repos', $this->tables());
        self::assertContains('webhooks', $this->tables());
    }

    public function testServeDryRunPreparesCodesSshWithoutStartingAnything(): void
    {
        // OpenSSH wire format of a fresh Ed25519 public key.
        $blob = pack('N', 11).'ssh-ed25519'.pack('N', 32).sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        $keyData = base64_encode($blob);
        $output = $this->soft(['serve', '--dry-run', '-v'], [
            'SOFT_SERVE_INITIAL_ADMIN_KEYS' => 'ssh-ed25519 '.$keyData.' admin@test',
            'SOFT_SERVE_SSH_LISTEN_ADDR' => '127.0.0.1:0',
        ]);

        self::assertStringContainsString('Dry run: nothing started.', $output);
        foreach (['ssh', 'http', 'worker'] as $process) {
            self::assertMatchesRegularExpression('/^\s+'.$process.'\s+\S/m', $output, $process.' is planned');
        }

        self::assertStringContainsString('bin/gickers-ssh', $output);
        self::assertStringContainsString('--listen=', $output);
        self::assertStringContainsString('--host-key='.$this->work.'/data/ssh/soft_serve_host_ed25519', $output);
        self::assertStringContainsString('--php=', $output);
        self::assertStringContainsString('--console=', $output);
        self::assertFileDoesNotExist($this->work.'/data/ssh/sshd_config');
        self::assertFileDoesNotExist($this->work.'/data/ssh/authorized_keys');
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $env
     */
    private function soft(array $args, array $env = []): string
    {
        $process = new Process([\PHP_BINARY, \dirname(__DIR__, 3).'/bin/soft', ...$args], null, [
            'APP_ENV' => 'test',
            'DATABASE_URL' => 'sqlite:///'.$this->work.'/db.sqlite',
            'SOFT_SERVE_DATA_PATH' => $this->work.'/data',
            ...$env,
        ]);
        $process->setTimeout(120);
        $process->mustRun();

        return $process->getOutput().$process->getErrorOutput();
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        $pdo = new \PDO('sqlite:'.$this->work.'/db.sqlite');
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

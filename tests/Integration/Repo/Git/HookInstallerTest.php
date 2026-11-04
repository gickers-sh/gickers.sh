<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repo\Git;

use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Repo\Infrastructure\Git\HookInstaller;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class HookInstallerTest extends TestCase
{
    private HookInstaller $installer;
    private GitRepositoryReader $reader;
    /** @var string[] */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        $this->installer = new HookInstaller('/app/bin/console');
        $this->reader = new GitRepositoryReader();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (is_dir($dir)) {
                (new Process(['rm', '-rf', $dir]))->run();
            }
        }
        $this->tempDirs = [];
    }

    private function createTempDir(string $prefix = 'hook_installer_test_'): string
    {
        $path = sys_get_temp_dir().'/'.$prefix.uniqid('', true);
        mkdir($path, 0755, true);
        $this->tempDirs[] = $path;

        return $path;
    }

    public function testInstallServerHooks(): void
    {
        $dir = $this->createTempDir('bare_hooks_');
        (new Process(['git', 'init', '--bare', '-b', 'main', $dir]))->mustRun();
        $repo = $this->reader->openRepository($dir);

        $consoleBin = '/app/bin/console';
        $this->installer->install($repo, $consoleBin);

        $expectedHooks = ['pre-receive', 'update', 'post-receive', 'post-update'];
        foreach ($expectedHooks as $hook) {
            $hookFile = $dir.'/hooks/'.$hook;
            $this->assertFileExists($hookFile);
            $this->assertTrue(is_executable($hookFile));
            $content = (string) file_get_contents($hookFile);
            $this->assertStringContainsString('${GIT_DIR}/hooks/${hookname}.d/*', $content);
            $this->assertStringContainsString('echo "${data}" | "${hook}" "$@"', $content);
            $this->assertStringContainsString('exit ${i}', $content);

            $softHookFile = $dir.'/hooks/'.$hook.'.d/soft-serve';
            $this->assertFileExists($softHookFile);
            $this->assertTrue(is_executable($softHookFile));
            $softContent = (string) file_get_contents($softHookFile);
            $this->assertStringContainsString('SOFT_SERVE_REPO_NAME', $softContent);
            $this->assertStringContainsString($consoleBin, $softContent);
            $this->assertStringContainsString('soft:hook:'.$hook, $softContent);

            if ('update' === $hook) {
                $this->assertStringContainsString('$1 $2 $3', $softContent);
            } elseif ('post-update' === $hook) {
                $this->assertStringContainsString('$@', $softContent);
            }
        }

        // Also verify string path support (used by AdminSyncHooksCommand)
        $dir2 = $this->createTempDir('bare_hooks_str_');
        (new Process(['git', 'init', '--bare', '-b', 'main', $dir2]))->mustRun();
        $this->installer->install($dir2, $consoleBin);
        $this->assertFileExists($dir2.'/hooks/pre-receive.d/soft-serve');
    }
}

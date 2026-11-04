<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repo\Git;

use App\Command\HookCommand;
use App\Command\HookPostReceiveCommand;
use App\Command\HookPostUpdateCommand;
use App\Command\HookPreReceiveCommand;
use App\Command\HookUpdateCommand;
use App\Repo\Domain\Git\GitRepository;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Repo\Infrastructure\Git\GitRepositoryWriter;
use App\Repo\Infrastructure\Git\HookInstaller;
use App\Service\Backend\BackendService;
use App\Service\CodeIntel\CodeIntelService;
use App\Service\Database\DatabaseManager;
use App\Service\Security\SsrfValidator;
use App\Service\Store\DataStore;
use App\Service\Webhook\WebhookDispatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

final class HookInstallerTest extends TestCase
{
    private HookInstaller $installer;
    private GitRepositoryWriter $writer;
    private GitRepositoryReader $reader;
    /** @var string[] */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        $this->installer = new HookInstaller('/app/bin/console');
        $this->writer = new GitRepositoryWriter();
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
            // Primary hook dispatcher script (matching hookTemplate from pkg/hooks/gen.go)
            $hookFile = $dir.'/hooks/'.$hook;
            $this->assertFileExists($hookFile);
            $this->assertTrue(is_executable($hookFile));
            $content = (string) file_get_contents($hookFile);
            $this->assertStringContainsString('${GIT_DIR}/hooks/${hookname}.d/*', $content);
            $this->assertStringContainsString('echo "${data}" | "${hook}" "$@"', $content);
            $this->assertStringContainsString('exit ${i}', $content);

            // Subhook script inside ${hook}.d/soft-serve (matching hooksTmpl from pkg/hooks/gen.go)
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

    public function testHookPreReceiveCommandWithCustomHook(): void
    {
        $tempDir = $this->createTempDir('hook_test_data_');
        $db = new DatabaseManager('sqlite::memory:', dataPath: $tempDir);
        $db->migrate();
        $store = new DataStore($db);
        $backend = new BackendService($store);

        $cmd = new HookPreReceiveCommand($backend);
        $tester = new CommandTester($cmd);

        // 1. Without custom hook
        $cmd->setInputStream("1111111111111111111111111111111111111111 2222222222222222222222222222222222222222 refs/heads/main\n");
        putenv('SOFT_SERVE_DATA_PATH='.$tempDir);
        putenv('SOFT_SERVE_REPO_NAME=test-repo');
        $res = $tester->execute([]);
        $this->assertSame(0, $res);

        // 2. With succeeding custom hook
        $customHookDir = $tempDir.'/hooks';
        @mkdir($customHookDir, 0755, true);
        $customHookFile = $customHookDir.'/pre-receive';
        file_put_contents($customHookFile, "#!/bin/sh\nread data\necho \"CUSTOM_HOOK_OK: \$data\"\nexit 0\n");
        chmod($customHookFile, 0755);

        $cmd->setInputStream("3333333333333333333333333333333333333333 4444444444444444444444444444444444444444 refs/heads/main\n");
        $res = $tester->execute([]);
        $this->assertSame(0, $res);
        $this->assertStringContainsString('CUSTOM_HOOK_OK: 3333333333333333333333333333333333333333', $tester->getDisplay());

        // 3. With failing custom hook
        file_put_contents($customHookFile, "#!/bin/sh\nexit 1\n");
        $cmd->setInputStream("old new ref\n");
        $res = $tester->execute([]);
        $this->assertSame(1, $res);

        putenv('SOFT_SERVE_DATA_PATH');
        putenv('SOFT_SERVE_REPO_NAME');
    }

    public function testHookUpdateCommandWithWebhooksAndCustomHook(): void
    {
        $tempDir = $this->createTempDir('hook_update_data_');
        $db = new DatabaseManager('sqlite::memory:', dataPath: $tempDir);
        $db->migrate();
        $store = new DataStore($db);
        $ssrf = new SsrfValidator();
        $webhookDispatcher = new WebhookDispatcher($store, $ssrf);
        $backend = new BackendService($store, $webhookDispatcher);

        $user = $backend->createUser('testuser', true);
        $repo = $backend->createRepositoryWithGit('repo1');
        $webhook = $store->createWebhook(
            repoId: (int) $repo['id'],
            url: 'https://example.com/webhook',
            secret: 'sec',
            events: [1, 2, 4],
        );

        putenv('SOFT_SERVE_DATA_PATH='.$tempDir);
        putenv('SOFT_SERVE_REPO_NAME=repo1');
        putenv('SOFT_SERVE_USERNAME=testuser');

        $cmd = new HookUpdateCommand($backend);
        $tester = new CommandTester($cmd);
        $res = $tester->execute([
            'ref-name' => 'refs/heads/feature',
            'old-rev' => '0000000000000000000000000000000000000000',
            'new-rev' => '5555555555555555555555555555555555555555',
        ]);
        $this->assertSame(0, $res);

        $deliveries = $store->listDeliveries($webhook->id);
        $this->assertGreaterThanOrEqual(2, count($deliveries));

        // Custom hook for update
        $customHookDir = $tempDir.'/hooks';
        @mkdir($customHookDir, 0755, true);
        $customHookFile = $customHookDir.'/update';
        file_put_contents($customHookFile, "#!/bin/sh\necho \"UPDATE_ARGS: \$1 \$2 \$3\"\nexit 0\n");
        chmod($customHookFile, 0755);

        $res = $tester->execute([
            'ref-name' => 'refs/heads/main',
            'old-rev' => '1111111111111111111111111111111111111111',
            'new-rev' => '2222222222222222222222222222222222222222',
        ]);
        $this->assertSame(0, $res);
        $this->assertStringContainsString('UPDATE_ARGS: refs/heads/main 1111111111111111111111111111111111111111 2222222222222222222222222222222222222222', $tester->getDisplay());

        // Failing custom hook
        file_put_contents($customHookFile, "#!/bin/sh\nexit 2\n");
        $res = $tester->execute([
            'ref-name' => 'refs/heads/main',
            'old-rev' => '1111111111111111111111111111111111111111',
            'new-rev' => '2222222222222222222222222222222222222222',
        ]);
        $this->assertSame(2, $res);

        putenv('SOFT_SERVE_DATA_PATH');
        putenv('SOFT_SERVE_REPO_NAME');
        putenv('SOFT_SERVE_USERNAME');
    }

    public function testHookPostReceiveCommand(): void
    {
        $tempDir = $this->createTempDir('hook_post_receive_');
        $db = new DatabaseManager('sqlite::memory:', dataPath: $tempDir);
        $db->migrate();
        $store = new DataStore($db);
        $ssrf = new SsrfValidator();
        $webhookDispatcher = new WebhookDispatcher($store, $ssrf);
        $backend = new BackendService($store, $webhookDispatcher);

        $user = $backend->createUser('alice', false);
        $repo = $backend->createRepositoryWithGit('repo_post');
        $webhook = $store->createWebhook(
            repoId: (int) $repo['id'],
            url: 'https://example.com/webhook',
            secret: 'sec',
            events: [4],
        );

        $codeIntelDir = $tempDir.'/codeintel';
        $codeIntel = new CodeIntelService(storageDir: $codeIntelDir);

        $cmd = new HookPostReceiveCommand($backend, $webhookDispatcher, $codeIntel);
        $tester = new CommandTester($cmd);

        putenv('SOFT_SERVE_DATA_PATH='.$tempDir);
        putenv('SOFT_SERVE_REPO_NAME=repo_post');
        putenv('SOFT_SERVE_USERNAME=alice');

        $cmd->setInputStream("0000000000000000000000000000000000000000 aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa refs/heads/main\n");
        $res = $tester->execute([]);
        $this->assertSame(0, $res);

        $deliveries = $store->listDeliveries($webhook->id);
        $this->assertCount(1, $deliveries);

        // Custom hook
        $customHookDir = $tempDir.'/hooks';
        @mkdir($customHookDir, 0755, true);
        $customHookFile = $customHookDir.'/post-receive';
        file_put_contents($customHookFile, "#!/bin/sh\necho \"POST_RECEIVE_HOOK_RAN\"\nexit 0\n");
        chmod($customHookFile, 0755);

        $cmd->setInputStream("0000000000000000000000000000000000000000 aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa refs/heads/main\n");
        $res = $tester->execute([]);
        $this->assertSame(0, $res);
        $this->assertStringContainsString('POST_RECEIVE_HOOK_RAN', $tester->getDisplay());

        putenv('SOFT_SERVE_DATA_PATH');
        putenv('SOFT_SERVE_REPO_NAME');
        putenv('SOFT_SERVE_USERNAME');
    }

    public function testHookPostUpdateCommand(): void
    {
        $tempDir = $this->createTempDir('hook_post_update_');
        $db = new DatabaseManager('sqlite::memory:', dataPath: $tempDir);
        $db->migrate();
        $store = new DataStore($db);
        $backend = new BackendService($store);
        $repo = $backend->createRepositoryWithGit('repo_post_update');
        $repoPath = $backend->getRepoPath('repo_post_update');

        $gitRepo = new GitRepository('repo_post_update', $repoPath, true);
        $this->writer->writeFile(
            repo: $gitRepo,
            branch: 'main',
            filePath: 'file.txt',
            content: 'Hello',
            message: 'Commit 1',
            authorName: 'Test',
            authorEmail: 'test@codes.sh'
        );

        $codeIntelDir = $tempDir.'/codeintel';
        $codeIntel = new CodeIntelService(storageDir: $codeIntelDir);

        $cmd = new HookPostUpdateCommand($backend, $codeIntel);
        $tester = new CommandTester($cmd);

        putenv('SOFT_SERVE_DATA_PATH='.$tempDir);
        putenv('SOFT_SERVE_REPO_NAME=repo_post_update');
        putenv('SOFT_SERVE_REPO_PATH='.$repoPath);

        $res = $tester->execute(['refs' => ['refs/heads/main']]);
        $this->assertSame(0, $res);

        $lastModifiedFile = $repoPath.'/info/last-modified';
        $this->assertFileExists($lastModifiedFile);
        $this->assertNotEmpty(trim((string) file_get_contents($lastModifiedFile)));

        // Custom hook
        $customHookDir = $tempDir.'/hooks';
        @mkdir($customHookDir, 0755, true);
        $customHookFile = $customHookDir.'/post-update';
        file_put_contents($customHookFile, "#!/bin/sh\necho \"POST_UPDATE_REFS: \$@\"\nexit 0\n");
        chmod($customHookFile, 0755);

        $res = $tester->execute(['refs' => ['refs/heads/main', 'refs/tags/v1.0.0']]);
        $this->assertSame(0, $res);
        $this->assertStringContainsString('POST_UPDATE_REFS: refs/heads/main refs/tags/v1.0.0', $tester->getDisplay());

        putenv('SOFT_SERVE_DATA_PATH');
        putenv('SOFT_SERVE_REPO_NAME');
        putenv('SOFT_SERVE_REPO_PATH');
    }

    public function testHookCommandDispatcherRouting(): void
    {
        $tempDir = $this->createTempDir('hook_dispatcher_');
        $db = new DatabaseManager('sqlite::memory:', dataPath: $tempDir);
        $db->migrate();
        $store = new DataStore($db);
        $backend = new BackendService($store);

        $preReceive = new HookPreReceiveCommand($backend);
        $update = new HookUpdateCommand($backend);
        $postReceive = new HookPostReceiveCommand($backend, new WebhookDispatcher($store, new SsrfValidator()));
        $postUpdate = new HookPostUpdateCommand($backend, new CodeIntelService());
        $dispatcher = new HookCommand();

        $app = new Application();
        $app->addCommand($preReceive);
        $app->addCommand($update);
        $app->addCommand($postReceive);
        $app->addCommand($postUpdate);
        $app->addCommand($dispatcher);

        $tester = new CommandTester($dispatcher);
        $res = $tester->execute([
            'hook-name' => 'update',
            'hook-args' => ['refs/heads/main', '0000000000000000000000000000000000000000', '1111111111111111111111111111111111111111'],
        ]);
        $this->assertSame(0, $res);
    }

    public function testCustomHookIgnoresMissingOrNonExecutable(): void
    {
        $tempDir = $this->createTempDir('custom_hook_ignore_');
        $db = new DatabaseManager('sqlite::memory:', dataPath: $tempDir);
        $db->migrate();
        $store = new DataStore($db);
        $backend = new BackendService($store);

        $cmd = new HookPreReceiveCommand($backend);
        $tester = new CommandTester($cmd);

        putenv('SOFT_SERVE_DATA_PATH='.$tempDir);
        putenv('SOFT_SERVE_REPO_NAME=repo_none');

        // 1. Missing hook
        $cmd->setInputStream('');
        $res = $tester->execute([]);
        $this->assertSame(0, $res);

        // 2. Non-executable hook
        $hooksDir = $tempDir.'/hooks';
        @mkdir($hooksDir, 0755, true);
        $hookFile = $hooksDir.'/pre-receive';
        file_put_contents($hookFile, "#!/bin/sh\nexit 1\n");
        chmod($hookFile, 0644);

        $res = $tester->execute([]);
        $this->assertSame(0, $res);

        putenv('SOFT_SERVE_DATA_PATH');
        putenv('SOFT_SERVE_REPO_NAME');
    }
}

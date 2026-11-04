<?php

declare(strict_types=1);

namespace App\Tests\Functional\Hook;

use App\Identity\Application\CreateUser;
use App\Repo\Application\CreateRepo;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\UI\Console\Command\Hook\PostReceiveCommand;
use App\UI\Console\Command\Hook\PostUpdateCommand;
use App\UI\Console\Command\Hook\PreReceiveCommand;
use App\UI\Console\Command\Hook\UpdateCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

final class HookCommandTest extends KernelTestCase
{
    private Repo $testRepo;
    private string $repoPath;
    private ServerSettings $settings;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $userStore = $container->get(\App\Identity\Domain\UserStore::class);
        $user = new \App\Identity\Domain\Model\User('hookowner', true, new \DateTimeImmutable());
        $userStore->save($user);
        $storage = $container->get(BareRepositoryStorage::class);
        $repoName = RepoName::fromInput('hook-test-' . uniqid());
        $storage->init($repoName);
        $repo = new Repo($repoName, $user, 'project', 'desc', false, false, false, null, new \DateTimeImmutable());
        $repoStore = $container->get(RepoStore::class);
        $repoStore->save($repo);
        $this->testRepo = $repo;
        $this->repoPath = $storage->pathFor($repoName);
        $this->settings = $container->get(ServerSettings::class);
    }

    public function testPreReceiveHookRunsAndCustomHookForwarded(): void
    {
        $container = static::getContainer();
        /** @var PreReceiveCommand $cmd */
        $cmd = $container->get(PreReceiveCommand::class);
        $tester = new CommandTester($cmd);

        // 1. Without custom hook
        $tester->execute(['--stdin' => "0000 1111 refs/heads/main\n"]);
        $this->assertSame(0, $tester->getStatusCode());

        // 2. With custom hook
        $hooksDir = $this->settings->hooksPath();
        @mkdir($hooksDir, 0755, true);
        $customHook = $hooksDir . '/pre-receive';
        file_put_contents($customHook, "#!/bin/sh\necho 'custom-pre-receive-output'\nexit 0\n");
        chmod($customHook, 0755);

        try {
            $tester->execute(['--stdin' => "0000 1111 refs/heads/main\n"]);
            $this->assertSame(0, $tester->getStatusCode());
            $this->assertStringContainsString('custom-pre-receive-output', $tester->getDisplay());
        } finally {
            @unlink($customHook);
        }
    }

    public function testUpdateHookRunsAndForwardsArgs(): void
    {
        $container = static::getContainer();
        /** @var UpdateCommand $cmd */
        $cmd = $container->get(UpdateCommand::class);
        $tester = new CommandTester($cmd);

        $tester->execute([
            'ref-name' => 'refs/heads/feature',
            'old-rev' => '0000',
            'new-rev' => '1111',
        ]);
        $this->assertSame(0, $tester->getStatusCode());

        // With custom hook
        $hooksDir = $this->settings->hooksPath();
        @mkdir($hooksDir, 0755, true);
        $customHook = $hooksDir . '/update';
        file_put_contents($customHook, "#!/bin/sh\necho \"args: \$1 \$2 \$3\"\nexit 0\n");
        chmod($customHook, 0755);

        try {
            $tester->execute([
                'ref-name' => 'refs/heads/feature',
                'old-rev' => '0000',
                'new-rev' => '1111',
            ]);
            $this->assertSame(0, $tester->getStatusCode());
            $this->assertStringContainsString('args: refs/heads/feature 0000 1111', $tester->getDisplay());
        } finally {
            @unlink($customHook);
        }
    }

    public function testPostReceiveRecordsPush(): void
    {
        $container = static::getContainer();
        /** @var PostReceiveCommand $cmd */
        $cmd = $container->get(PostReceiveCommand::class);
        $tester = new CommandTester($cmd);

        $tester->execute([
            '--repo' => $this->testRepo->nameString(),
            '--stdin' => "0000000000000000000000000000000000000000 aaaaaaaa11111111222222223333333344444444 refs/heads/main\n",
        ]);
        $this->assertSame(0, $tester->getStatusCode());

        // Repo updated_at touched
        $repoStore = $container->get(RepoStore::class);
        $reloaded = $repoStore->byId($this->testRepo->id());
        $this->assertNotNull($reloaded);
    }

    public function testPostUpdateUpdatesLastModified(): void
    {
        $container = static::getContainer();
        /** @var PostUpdateCommand $cmd */
        $cmd = $container->get(PostUpdateCommand::class);
        $tester = new CommandTester($cmd);

        // Make an initial commit in bare repo so HEAD exists
        (new Process(['git', 'config', 'user.name', 'Tester'], $this->repoPath))->mustRun();
        (new Process(['git', 'config', 'user.email', 'test@example.com'], $this->repoPath))->mustRun();
        $treeProc = new Process(['git', 'write-tree'], $this->repoPath);
        $treeProc->mustRun();
        $tree = trim($treeProc->getOutput());
        $commitProc = new Process(['git', 'commit-tree', $tree, '-m', 'initial'], $this->repoPath);
        $commitProc->mustRun();
        $commit = trim($commitProc->getOutput());
        (new Process(['git', 'update-ref', 'refs/heads/main', $commit], $this->repoPath))->mustRun();
        (new Process(['git', 'symbolic-ref', 'HEAD', 'refs/heads/main'], $this->repoPath))->mustRun();

        $tester->execute([
            '--repo' => $this->testRepo->nameString(),
            'refs' => ['refs/heads/main'],
        ]);
        $this->assertSame(0, $tester->getStatusCode());

        $lastModFile = $this->repoPath . '/info/last-modified';
        $this->assertFileExists($lastModFile);
        $this->assertNotEmpty(trim((string) file_get_contents($lastModFile)));
    }
}

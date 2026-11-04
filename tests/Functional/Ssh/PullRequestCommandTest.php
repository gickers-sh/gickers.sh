<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ssh;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Git\GitRepositoryWriter;
use App\UI\Ssh\SshSession;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

final class PullRequestCommandTest extends KernelTestCase
{
    private User $owner;
    private Repo $repo;
    private SshSession $session;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $userStore = $container->get(UserStore::class);
        $this->owner = new User('sshuser'.uniqid(), true, new \DateTimeImmutable());
        $userStore->save($this->owner);

        /** @var SshSession $session */
        $session = $container->get('test.ssh_session');
        $this->session = $session;
        $this->session->authenticate($this->owner, 'SHA256:fingerprint');

        $storage = $container->get(BareRepositoryStorage::class);
        $repoName = RepoName::fromInput('pr-ssh-'.uniqid());
        $storage->init($repoName);

        $this->repo = new Repo($repoName, $this->owner, 'proj', 'desc', false, false, false, null, new \DateTimeImmutable());
        $container->get(RepoStore::class)->save($this->repo);

        $repoPath = $storage->pathFor($repoName);
        $writer = new GitRepositoryWriter();
        $reader = new \App\Repo\Infrastructure\Git\GitRepositoryReader();
        $gitRepo = $reader->openRepository($repoPath);
        $writer->writeFile($gitRepo, 'main', 'base.txt', "base\n", 'Initial', 'Tester', 'test@example.com');
        (new Process(['git', 'branch', 'feat', 'main'], $repoPath))->mustRun();
        $writer->writeFile($gitRepo, 'feat', 'feat.txt', "feat\n", 'Feat commit', 'Tester', 'test@example.com');
    }

    public function testPullRequestLifecycleCommands(): void
    {
        $container = static::getContainer();

        // 1. List when empty
        $listCmd = $container->get('test.pr_list_command');
        $tester = new CommandTester($listCmd);
        $tester->execute(['repository' => $this->repo->nameString()]);
        $this->assertStringContainsString('No pull requests found.', $tester->getDisplay());

        // 2. Create
        $createCmd = $container->get('test.pr_create_command');
        $tester = new CommandTester($createCmd);
        $tester->execute([
            'repository' => $this->repo->nameString(),
            '--head' => 'feat',
            '--base' => 'main',
            '--title' => 'My Feature PR',
        ]);
        $this->assertStringContainsString('Created pull request #1: My Feature PR (main ← feat)', $tester->getDisplay());

        // 3. List with 1 item
        $tester = new CommandTester($listCmd);
        $tester->execute(['repository' => $this->repo->nameString()]);
        $this->assertStringContainsString('#1    [OPEN]    My Feature PR                   (main ← feat)', $tester->getDisplay());

        // 4. View
        $viewCmd = $container->get('test.pr_view_command');
        $tester = new CommandTester($viewCmd);
        $tester->execute(['repository' => $this->repo->nameString(), 'number' => '1']);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Pull Request #1: My Feature PR', $display);
        $this->assertStringContainsString('Status:      OPEN', $display);
        $this->assertStringContainsString('Branches:    main ← feat', $display);

        // 5. Diff
        $diffCmd = $container->get('test.pr_diff_command');
        $tester = new CommandTester($diffCmd);
        $tester->execute(['repository' => $this->repo->nameString(), 'number' => '1']);
        $this->assertStringContainsString('diff --git', $tester->getDisplay());

        // 6. Comment
        $commentCmd = $container->get('test.pr_comment_command');
        $tester = new CommandTester($commentCmd);
        $tester->execute(['repository' => $this->repo->nameString(), 'number' => '1', 'body' => ['Great', 'work!']]);
        $this->assertStringContainsString('Comment added (ID: ', $tester->getDisplay());

        // 7. Close and Reopen
        $closeCmd = $container->get('test.pr_close_command');
        $tester = new CommandTester($closeCmd);
        $tester->execute(['repository' => $this->repo->nameString(), 'number' => '1']);
        $this->assertStringContainsString('Closed pull request #1', $tester->getDisplay());

        $reopenCmd = $container->get('test.pr_reopen_command');
        $tester = new CommandTester($reopenCmd);
        $tester->execute(['repository' => $this->repo->nameString(), 'number' => '1']);
        $this->assertStringContainsString('Reopened pull request #1', $tester->getDisplay());

        // 8. Merge
        $mergeCmd = $container->get('test.pr_merge_command');
        $tester = new CommandTester($mergeCmd);
        $tester->execute(['repository' => $this->repo->nameString(), 'number' => '1']);
        $this->assertStringContainsString('Successfully merged pull request #1 (commit ', $tester->getDisplay());

        // 9. Delete
        $delCmd = $container->get('test.pr_delete_command');
        $tester = new CommandTester($delCmd);
        $tester->execute(['repository' => $this->repo->nameString(), 'number' => '1']);
        $this->assertStringContainsString('Pull request #1 deleted', $tester->getDisplay());
    }
}

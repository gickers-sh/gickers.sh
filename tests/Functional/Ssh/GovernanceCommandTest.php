<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ssh;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Tests\GitTestHelper;
use App\UI\Ssh\SshSession;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Functional coverage for push governance: the SSH `repo branch protect/unprotect`
 * and `repo signatures` commands, and the `soft:hook:pre-receive` enforcement that
 * reads the server-set `SOFT_SERVE_REPO_NAME`/`SOFT_SERVE_USERNAME` identity.
 */
final class GovernanceCommandTest extends KernelTestCase
{
    private const ZERO = '0000000000000000000000000000000000000000';

    private User $owner;
    private RepoName $repoName;
    private string $head;
    private SshSession $session;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $userStore = $container->get(UserStore::class);
        $this->owner = new User('govowner-'.bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $userStore->save($this->owner);

        $this->session = $container->get('test.ssh_session');
        $this->session->authenticate($this->owner, 'SHA256:fingerprint');

        $storage = $container->get(BareRepositoryStorage::class);
        $this->repoName = RepoName::fromInput('gov-'.bin2hex(random_bytes(3)));
        $gitRepo = $storage->init($this->repoName, 'main');
        $this->head = GitTestHelper::writeFile($gitRepo, 'main', 'README.md', "# demo\n", 'init');

        $repo = new Repo($this->repoName, $this->owner, 'proj', 'desc', false, false, false, null, new \DateTimeImmutable());
        $container->get(RepoStore::class)->save($repo);
    }

    private function tester(string $serviceId): CommandTester
    {
        return new CommandTester(static::getContainer()->get($serviceId));
    }

    private function reloadRepo(): Repo
    {
        static::getContainer()->get('doctrine')->getManager()->clear();
        $repo = static::getContainer()->get(RepoStore::class)->byName($this->repoName);
        self::assertNotNull($repo);

        return $repo;
    }

    public function testProtectCreatesRuleAndUnprotectRemovesIt(): void
    {
        $this->tester('test.repo_branch_protect_command')->execute([
            'repository' => $this->repoName->value,
            'pattern' => 'main',
            '--no-force' => true,
            '--no-delete' => true,
        ]);

        $rule = $this->reloadRepo()->branchProtectionFor('main');
        self::assertNotNull($rule);
        self::assertTrue($rule->noForce());
        self::assertTrue($rule->noDelete());
        self::assertFalse($rule->adminsOnly());

        $this->tester('test.repo_branch_unprotect_command')->execute([
            'repository' => $this->repoName->value,
            'pattern' => 'main',
        ]);

        self::assertNull($this->reloadRepo()->branchProtectionFor('main'));
    }

    public function testSignaturesGetAndSet(): void
    {
        $get = $this->tester('test.repo_signatures_command');
        $get->execute(['repository' => $this->repoName->value]);
        self::assertSame('false', trim($get->getDisplay()));

        $this->tester('test.repo_signatures_command')->execute([
            'repository' => $this->repoName->value,
            'value' => 'true',
        ]);
        self::assertTrue($this->reloadRepo()->requireSignedCommits());

        $get2 = $this->tester('test.repo_signatures_command');
        $get2->execute(['repository' => $this->repoName->value]);
        self::assertSame('true', trim($get2->getDisplay()));
    }

    public function testProtectDeniedForNonAdministrator(): void
    {
        $stranger = new User('stranger-'.bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        static::getContainer()->get(UserStore::class)->save($stranger);
        $this->session->authenticate($stranger, 'SHA256:other');

        $this->expectException(\Throwable::class);
        $this->tester('test.repo_branch_protect_command')->execute([
            'repository' => $this->repoName->value,
            'pattern' => 'main',
            '--no-delete' => true,
        ]);
    }

    public function testPreReceiveRejectsDefaultBranchDeletion(): void
    {
        $exit = $this->runPreReceive(sprintf("%s %s refs/heads/main\n", $this->head, self::ZERO));

        self::assertNotSame(0, $exit['code']);
        self::assertStringContainsString('default branch', $exit['stderr']);
    }

    public function testPreReceiveRejectsForceUpdateOnProtectedBranch(): void
    {
        $storage = static::getContainer()->get(BareRepositoryStorage::class);
        $gitRepo = $storage->open($this->repoName);
        $divergent = GitTestHelper::writeFile($gitRepo, 'orphan', 'x.txt', "x\n", 'orphan');

        $repo = static::getContainer()->get(RepoStore::class)->byName($this->repoName);
        self::assertNotNull($repo);
        $repo->protectBranch('main', noForce: true, noDelete: false, adminsOnly: false, now: new \DateTimeImmutable());
        static::getContainer()->get(RepoStore::class)->save($repo);

        $exit = $this->runPreReceive(sprintf("%s %s refs/heads/main\n", $this->head, $divergent));

        self::assertNotSame(0, $exit['code']);
        self::assertStringContainsString('force', $exit['stderr']);
    }

    public function testPreReceiveAllowsFastForwardWithoutGovernance(): void
    {
        $storage = static::getContainer()->get(BareRepositoryStorage::class);
        $gitRepo = $storage->open($this->repoName);
        $next = GitTestHelper::writeFile($gitRepo, 'main', 'next.txt', "n\n", 'next');

        $exit = $this->runPreReceive(sprintf("%s %s refs/heads/main\n", $this->head, $next));

        self::assertSame(0, $exit['code']);
    }

    /**
     * @return array{code: int, stderr: string}
     */
    private function runPreReceive(string $stdin): array
    {
        $cmd = static::getContainer()->get('test.pre_receive_command');
        $cmd->setInputStream($stdin);
        $tester = new CommandTester($cmd);

        $tester->execute([
            '--repo' => $this->repoName->value,
            '--actor' => $this->owner->username(),
        ], ['capture_stderr_separately' => true]);

        return ['code' => $tester->getStatusCode(), 'stderr' => $tester->getErrorOutput()];
    }
}

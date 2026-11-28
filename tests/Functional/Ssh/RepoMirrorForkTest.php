<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ssh;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\Command\Repo\RepoForkCommand;
use App\UI\Ssh\Command\Repo\RepoMirrorCommand;
use App\UI\Ssh\SshSession;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class RepoMirrorForkTest extends KernelTestCase
{
    private User $user;
    private RepoName $repoName;
    private SshSession $session;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $userStore = $container->get(UserStore::class);
        $this->user = new User('tester-' . bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $userStore->save($this->user);

        $this->session = $container->get('test.ssh_session');
        $this->session->authenticate($this->user, 'SHA256:fingerprint');

        $storage = $container->get(BareRepositoryStorage::class);
        $this->repoName = RepoName::fromInput($this->user->username() . '/source-repo');
        $storage->init($this->repoName, 'main');

        $repo = new Repo($this->repoName, $this->user, 'proj', 'desc', false, false, false, null, new \DateTimeImmutable());
        $container->get(RepoStore::class)->save($repo);
    }

    public function testMirrorGetAndSet(): void
    {
        $container = static::getContainer();
        $command = $container->get(RepoMirrorCommand::class);
        $tester = new CommandTester($command);

        // 1. Get mirror status (default false)
        $tester->execute(['repository' => $this->repoName->value]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('false', $tester->getDisplay());

        // 2. Set mirror status to true
        $tester->execute(['repository' => $this->repoName->value, 'mirror' => 'true']);
        self::assertSame(0, $tester->getStatusCode());

        // 3. Get mirror status again (now true)
        $tester->execute(['repository' => $this->repoName->value]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('true', $tester->getDisplay());
    }

    public function testMirrorRejectsBlockedUrl(): void
    {
        $container = static::getContainer();
        $command = $container->get(RepoMirrorCommand::class);
        $tester = new CommandTester($command);

        $this->expectException(InvalidInput::class);
        $tester->execute(['repository' => 'http://127.0.0.1/evil.git']);
    }

    public function testForkRejectsBlockedUrl(): void
    {
        $container = static::getContainer();
        $command = $container->get(RepoForkCommand::class);
        $tester = new CommandTester($command);

        $this->expectException(InvalidInput::class);
        $tester->execute(['source' => 'http://127.0.0.1/evil.git']);
    }

    public function testForkLocalRepoWithCustomName(): void
    {
        $container = static::getContainer();
        $command = $container->get(RepoForkCommand::class);
        $tester = new CommandTester($command);

        $tester->execute(['source' => $this->repoName->value, 'new_name' => 'forked-custom'], ['capture_stderr_separately' => true]);
        self::assertSame(0, $tester->getStatusCode());

        $expectedTarget = $this->user->username() . '/forked-custom';
        self::assertStringContainsString(sprintf('Forked %s into %s', $this->repoName->value, $expectedTarget), $tester->getErrorOutput());

        $repoStore = $container->get(RepoStore::class);
        $forkRepo = $repoStore->byName(RepoName::fromInput($expectedTarget));
        self::assertNotNull($forkRepo);
        self::assertSame($this->repoName->value, $forkRepo->forkOf()?->nameString());
    }

    public function testForkLocalRepoWithDefaultDestination(): void
    {
        $container = static::getContainer();
        $userStore = $container->get(UserStore::class);
        $bob = new User('bob-' . bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $userStore->save($bob);

        $this->session->authenticate($bob, 'SHA256:bobfingerprint');

        $command = $container->get(RepoForkCommand::class);
        $tester = new CommandTester($command);

        // Bob forks tester's repo with empty destination
        $tester->execute(['source' => $this->repoName->value], ['capture_stderr_separately' => true]);
        self::assertSame(0, $tester->getStatusCode());

        $expectedTarget = $bob->username() . '/source-repo';
        self::assertStringContainsString(sprintf('Forked %s into %s', $this->repoName->value, $expectedTarget), $tester->getErrorOutput());

        $repoStore = $container->get(RepoStore::class);
        $forkRepo = $repoStore->byName(RepoName::fromInput($expectedTarget));
        self::assertNotNull($forkRepo);
        self::assertSame($this->repoName->value, $forkRepo->forkOf()?->nameString());
    }

    public function testMirrorLocalGickersRepoWithDefaultDestination(): void
    {
        $container = static::getContainer();
        $userStore = $container->get(UserStore::class);
        $charlie = new User('charlie-' . bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $userStore->save($charlie);

        $this->session->authenticate($charlie, 'SHA256:charliefingerprint');

        $command = $container->get(RepoMirrorCommand::class);
        $tester = new CommandTester($command);

        // Charlie mirrors tester's repo with empty destination -> charlie/source-repo
        $tester->execute(['repository' => $this->repoName->value], ['capture_stderr_separately' => true]);
        self::assertSame(0, $tester->getStatusCode());

        $expectedTarget = $charlie->username() . '/source-repo';
        self::assertStringContainsString(sprintf('Mirrored %s into %s', $this->repoName->value, $expectedTarget), $tester->getErrorOutput());

        $repoStore = $container->get(RepoStore::class);
        $mirrorRepo = $repoStore->byName(RepoName::fromInput($expectedTarget));
        self::assertNotNull($mirrorRepo);
        self::assertTrue($mirrorRepo->isMirror());
        self::assertNull($mirrorRepo->forkOf());
    }

    public function testMirrorLocalGickersRepoWithCustomDestination(): void
    {
        $container = static::getContainer();
        $userStore = $container->get(UserStore::class);
        $david = new User('david-' . bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $userStore->save($david);

        $this->session->authenticate($david, 'SHA256:davidfingerprint');

        $command = $container->get(RepoMirrorCommand::class);
        $tester = new CommandTester($command);

        // David mirrors tester's repo with custom destination -> david/custom-mirror
        $tester->execute(['repository' => $this->repoName->value, 'mirror' => 'custom-mirror'], ['capture_stderr_separately' => true]);
        self::assertSame(0, $tester->getStatusCode());

        $expectedTarget = $david->username() . '/custom-mirror';
        self::assertStringContainsString(sprintf('Mirrored %s into %s', $this->repoName->value, $expectedTarget), $tester->getErrorOutput());

        $repoStore = $container->get(RepoStore::class);
        $mirrorRepo = $repoStore->byName(RepoName::fromInput($expectedTarget));
        self::assertNotNull($mirrorRepo);
        self::assertTrue($mirrorRepo->isMirror());
    }
}

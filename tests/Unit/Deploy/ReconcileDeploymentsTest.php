<?php

declare(strict_types=1);

namespace App\Tests\Unit\Deploy;

use App\Deploy\Application\ReconcileDeployments;
use App\Deploy\Domain\DeployRefType;
use App\Deploy\Domain\DeployStatus;
use App\Deploy\Domain\DeployStore;
use App\Deploy\Domain\Model\DeployDomain;
use App\Deploy\Domain\Model\DeployEnvironment;
use App\Deploy\Domain\Model\DeployRelease;
use App\Deploy\Domain\ReverseProxyRouter;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Shared\Domain\Exception\AccessDenied;
use App\Tests\GitTestHelper;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReconcileDeploymentsTest extends KernelTestCase
{
    private User $admin;
    private User $regularUser;
    private Repo $repo;
    private ReconcileDeployments $service;
    private DeployStore $deployStore;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $userStore = $container->get(UserStore::class);
        $this->admin = new User('recadmin-'.bin2hex(random_bytes(3)), true, new \DateTimeImmutable());
        $userStore->save($this->admin);

        $this->regularUser = new User('recuser-'.bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $userStore->save($this->regularUser);

        $storage = $container->get(BareRepositoryStorage::class);
        $repoName = RepoName::fromInput('rec-app-'.bin2hex(random_bytes(3)));
        $gitRepo = $storage->init($repoName, 'main');
        GitTestHelper::writeFile($gitRepo, 'main', 'Dockerfile', "FROM alpine\n", 'init main');

        $this->repo = new Repo($repoName, $this->admin, 'proj', 'desc', false, false, false, null, new \DateTimeImmutable());
        $container->get(RepoStore::class)->save($this->repo);

        $this->deployStore = $container->get(DeployStore::class);
        $this->service = $container->get(ReconcileDeployments::class);
    }

    public function testReconcileCaddyRoutesRunsWithoutError(): void
    {
        $count = $this->service->reconcileCaddyRoutes(reload: false);
        self::assertGreaterThanOrEqual(0, $count);
    }

    public function testRedeployAllDeniedForNonAdmin(): void
    {
        $this->expectException(AccessDenied::class);
        $this->service->redeploy($this->regularUser, all: true);
    }

    public function testRedeployRepoActiveEnvironments(): void
    {
        $env = new DeployEnvironment($this->repo, 'main', DeployRefType::Branch, new \DateTimeImmutable());
        $this->deployStore->saveEnvironment($env);

        $releases = $this->service->redeploy($this->admin, $this->repo->name(), 'main');
        self::assertCount(1, $releases);
        self::assertSame('main', $releases[0]->environment()->refName());

        $allReleases = $this->service->redeploy($this->admin, all: true);
        self::assertGreaterThanOrEqual(1, count($allReleases));
    }

    public function testReconcileOnBootOnlyRedeploysRunningEnvironments(): void
    {
        $runningEnv = new DeployEnvironment($this->repo, 'main', DeployRefType::Branch, new \DateTimeImmutable());
        $runningEnv->setStatus(DeployStatus::Running, new \DateTimeImmutable());
        $this->deployStore->saveEnvironment($runningEnv);

        $stoppedEnv = new DeployEnvironment($this->repo, 'staging', DeployRefType::Branch, new \DateTimeImmutable());
        $stoppedEnv->setStatus(DeployStatus::Stopped, new \DateTimeImmutable());
        $this->deployStore->saveEnvironment($stoppedEnv);

        $releases = $this->service->reconcileOnBoot();

        self::assertCount(1, $releases);
        self::assertSame('main', $releases[0]->environment()->refName());
    }
}

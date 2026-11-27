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

    public function testReconcileContainerHealthMarksCrashedEnvironmentAndReleaseAsFailed(): void
    {
        $now = new \DateTimeImmutable();
        $env = new DeployEnvironment($this->repo, 'main', DeployRefType::Branch, $now);
        $env->markDeployed('04a54bef59c494670dac37af76384308cf2552b3', $now);
        $this->deployStore->saveEnvironment($env);

        $release = new DeployRelease($env, '04a54bef59c494670dac37af76384308cf2552b3', $this->admin, $now);
        $release->appendLog("==> Deployment succeeded.\n");
        $release->markSucceeded($now);
        $this->deployStore->saveRelease($release);

        $fakeOrchestrator = new class implements \App\Deploy\Domain\ContainerOrchestrator {
            public bool $stopped = false;

            public function deploy(DeployEnvironment $environment, string $commitSha, \App\Deploy\Domain\Spec\ComposeSpec $spec, \App\Deploy\Domain\DeployQuota $quota, array $decryptedEnvVars, array $exposedServices, callable $onLog): void
            {
            }

            public function stop(DeployEnvironment $environment): void
            {
                $this->stopped = true;
            }

            public function restart(DeployEnvironment $environment): void
            {
            }

            public function destroy(DeployEnvironment $environment): void
            {
            }

            public function serviceLogs(DeployEnvironment $environment, ?string $serviceName = null, int $tail = 200): string
            {
                return 'Fatal error: Uncaught Error in /app/index.php:12';
            }

            public function streamServiceLogs(DeployEnvironment $environment, ?string $serviceName, callable $onOutput, int $tail = 100): void
            {
            }

            public function exec(DeployEnvironment $environment, string $serviceName, array $command): array
            {
                return ['exitCode' => 0, 'output' => ''];
            }

            public function inspectServices(DeployEnvironment $environment): array
            {
                return [
                    [
                        'service' => 'app',
                        'status' => 'exited',
                        'exitCode' => 1,
                        'restartCount' => 5,
                        'oomKilled' => false,
                        'error' => '',
                    ],
                ];
            }
        };

        $container = static::getContainer();
        $reconciler = new ReconcileDeployments(
            $this->deployStore,
            $container->get(ReverseProxyRouter::class),
            $container->get(\App\Deploy\Application\TriggerDeploy::class),
            $container->get(\App\Deploy\Application\DeployAccessResolver::class),
            $container->get(\App\Repo\Domain\RepoDirectory::class),
            $container->get(\App\Repo\Infrastructure\Git\GitRepositoryReader::class),
            $container->get(\Psr\Clock\ClockInterface::class),
            $fakeOrchestrator,
        );

        $failed = $reconciler->reconcileContainerHealth($env);

        self::assertCount(1, $failed);
        self::assertTrue($fakeOrchestrator->stopped);
        self::assertSame(DeployStatus::Failed, $env->status());

        $updatedRelease = $this->deployStore->latestReleaseForEnvironment($env);
        self::assertNotNull($updatedRelease);
        self::assertSame(\App\Deploy\Domain\ReleaseStatus::Failed, $updatedRelease->status());
        self::assertStringContainsString('stopped after 5 retry attempt(s)', $updatedRelease->buildLog());
        self::assertStringContainsString('Fatal error: Uncaught Error in /app/index.php:12', $updatedRelease->buildLog());
    }

    public function testMaxOneConcurrentDeploymentPerRepoAndRef(): void
    {
        $now = new \DateTimeImmutable();
        $env = new DeployEnvironment($this->repo, 'main', DeployRefType::Branch, $now);
        $this->deployStore->saveEnvironment($env);

        /** @var \App\Deploy\Application\TriggerDeploy $triggerDeploy */
        $triggerDeploy = static::getContainer()->get(\App\Deploy\Application\TriggerDeploy::class);

        // 1. First deployment queues release #1
        $rel1 = ($triggerDeploy)($this->admin, $this->repo->name(), 'main');
        self::assertSame(\App\Deploy\Domain\ReleaseStatus::Queued, $rel1->status());

        // 2. Triggering again while #1 is still Queued coalesces into release #1 instead of creating a duplicate
        $rel2 = ($triggerDeploy)($this->admin, $this->repo->name(), 'main');
        self::assertSame($rel1->id(), $rel2->id());
        self::assertCount(1, $this->deployStore->activeReleasesForEnvironment($env));

        // 3. Once release #1 transitions to Building, a manual trigger/redeploy throws Conflict
        $rel1->markBuilding();
        $this->deployStore->saveRelease($rel1);

        try {
            ($triggerDeploy)($this->admin, $this->repo->name(), 'main');
            self::fail('Expected Conflict when a deployment is already Building for the same repo + ref');
        } catch (\App\Shared\Domain\Exception\Conflict $e) {
            self::assertStringContainsString('is already in progress for', $e->getMessage());
        }

        // 4. Push/system events with coalesceIfActive=true do not spawn a second concurrent release while Building
        $coalesced = $triggerDeploy->queueForEnvironment($env, $rel1->commitSha(), $this->admin, coalesceIfActive: true);
        self::assertSame($rel1->id(), $coalesced->id());
        self::assertCount(1, $this->deployStore->activeReleasesForEnvironment($env));

        // 5. Another ref (e.g. "staging") on the same repo can still deploy independently
        $storage = static::getContainer()->get(BareRepositoryStorage::class);
        $gitRepo = $storage->open($this->repo->name());
        GitTestHelper::writeFile($gitRepo, 'staging', 'Dockerfile', "FROM alpine\n", 'init staging');

        $stagingEnv = new DeployEnvironment($this->repo, 'staging', DeployRefType::Branch, $now);
        $this->deployStore->saveEnvironment($stagingEnv);

        $stagingRel = ($triggerDeploy)($this->admin, $this->repo->name(), 'staging');
        self::assertNotSame($rel1->id(), $stagingRel->id());
        self::assertSame('staging', $stagingRel->environment()->refName());
    }
}

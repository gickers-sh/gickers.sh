<?php

declare(strict_types=1);

namespace App\Tests\Unit\Deploy;

use App\Access\Application\AccessGuard;
use App\Access\Application\AccessResolver;
use App\Access\Application\ServerPolicy;
use App\Access\Domain\SettingKey;
use App\Access\Domain\ServerSetting;
use App\Deploy\Application\DeployAccessResolver;
use App\Deploy\Domain\ContainerOrchestrator;
use App\Deploy\Domain\DeployStore;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\CollaboratorStore;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoDirectory;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Infrastructure\Config\ServerSettings;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class DeployAvailabilityTest extends TestCase
{
    private function createResolver(?ContainerOrchestrator $orchestrator): DeployAccessResolver
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(static fn (string $class, mixed $id) => match ($id) {
            SettingKey::DeployPolicy => new ServerSetting($id, 'admins-only', $now),
            SettingKey::AnonAccess => new ServerSetting($id, 'no-access', $now),
            default => null,
        });

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn($now);

        $serverSettings = new ServerSettings(
            name: 'Gickers.sh',
            dataPath: sys_get_temp_dir(),
            sshListenAddr: '127.0.0.1:2223',
            sshPublicUrl: 'ssh://localhost:2223',
            httpListenAddr: '127.0.0.1:23232',
            httpPublicUrl: 'http://localhost:23232',
            lfsEnabled: true,
            anonAccess: 'no-access',
            allowKeyless: true,
            initialAdminKeys: '',
            initialAdminToken: '',
            allowSignup: true,
        );

        $serverPolicy = new ServerPolicy($em, $serverSettings, $clock);
        $repoStore = $this->createStub(RepoStore::class);
        $collabStore = $this->createStub(CollaboratorStore::class);
        $repoDir = $this->createStub(RepoDirectory::class);
        $accessResolver = new AccessResolver($serverPolicy, $repoStore, $collabStore, $repoDir);
        $accessGuard = new AccessGuard($accessResolver, $repoStore);
        $deployStore = $this->createStub(DeployStore::class);

        return new DeployAccessResolver($serverPolicy, $accessResolver, $accessGuard, $deployStore, $orchestrator);
    }

    public function testDeployDisabledWhenOrchestratorUnavailable(): void
    {
        $orchestrator = $this->createMock(ContainerOrchestrator::class);
        $orchestrator->method('isAvailable')->willReturn(false);

        $resolver = $this->createResolver($orchestrator);

        self::assertFalse($resolver->isDeploySupported());

        $owner = new User('owner', true, new \DateTimeImmutable());
        $repo = new Repo(RepoName::fromInput('user/my-repo'), $owner, 'my-repo', 'desc', false, false, false, null, new \DateTimeImmutable());

        self::assertFalse($resolver->isRepoRefDeployAllowed($repo, 'main', $owner));

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Deployment engine is disabled on this server: Docker is not available or running.');

        $resolver->assertCanManageDeploy($owner, 'user/my-repo', 'main');
    }

    public function testDeployEnabledWhenOrchestratorAvailable(): void
    {
        $orchestrator = $this->createMock(ContainerOrchestrator::class);
        $orchestrator->method('isAvailable')->willReturn(true);

        $resolver = $this->createResolver($orchestrator);

        self::assertTrue($resolver->isDeploySupported());
    }
}

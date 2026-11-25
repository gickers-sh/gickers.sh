<?php

declare(strict_types=1);

namespace App\Tests\Unit\Deploy;

use App\Deploy\Domain\DeployRefType;
use App\Deploy\Domain\Model\DeployEnvironment;
use App\Deploy\Domain\Model\DeployRelease;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use PHPUnit\Framework\TestCase;

final class DeployReleaseTest extends TestCase
{
    private User $owner;
    private Repo $repo;
    private DeployEnvironment $env;

    protected function setUp(): void
    {
        $this->owner = new User('duck', false, new \DateTimeImmutable('2026-01-01 00:00:00'));
        $this->repo = new Repo(RepoName::fromInput('os.directory'), $this->owner, 'os.directory', 'Test project', false, false, false, null, new \DateTimeImmutable('2026-01-01 00:00:00'));
        $this->env = new DeployEnvironment($this->repo, 'main', DeployRefType::Branch, new \DateTimeImmutable('2026-01-01 00:00:00'));
    }

    public function testTriggerDisplayFallsBackToOwnerWhenTriggeredByIsNull(): void
    {
        $release = new DeployRelease($this->env, '306291fd7651bc84853e2c9240262322fc7abfec', null, new \DateTimeImmutable('2026-11-22 17:37:23'));

        self::assertSame('@duck (git push)', $release->triggerDisplay());
    }

    public function testTriggerDisplayWithExplicitUserAndPushLog(): void
    {
        $pusher = new User('alice', false, new \DateTimeImmutable('2026-01-01 00:00:00'));
        $release = new DeployRelease($this->env, '306291fd7651bc84853e2c9240262322fc7abfec', $pusher, new \DateTimeImmutable('2026-11-22 17:37:23'));
        $release->appendLog("==> Triggered by @alice (git push)\n");

        self::assertSame('@alice (git push)', $release->triggerDisplay());
    }

    public function testTriggerDisplayWithExplicitUserAndManualLog(): void
    {
        $user = new User('duck', false, new \DateTimeImmutable('2026-01-01 00:00:00'));
        $release = new DeployRelease($this->env, '306291fd7651bc84853e2c9240262322fc7abfec', $user, new \DateTimeImmutable('2026-11-22 06:07:15'));
        $release->appendLog("==> Triggered by @duck (manual)\n");

        self::assertSame('@duck (manual)', $release->triggerDisplay());
    }

    public function testTriggerDisplayWithExplicitUserWithoutLogHint(): void
    {
        $user = new User('duck', false, new \DateTimeImmutable('2026-01-01 00:00:00'));
        $release = new DeployRelease($this->env, '306291fd7651bc84853e2c9240262322fc7abfec', $user, new \DateTimeImmutable('2026-11-22 06:07:15'));

        self::assertSame('@duck', $release->triggerDisplay());
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repo;

use App\Repo\Domain\Event\RepoVisibilityChanged;

final class UpdateRepoSettingsTest extends RepoUseCaseTestCase
{
    public function testVisibilityEventOnlyWhenChanged(): void
    {
        $alice = $this->user('alice');
        $repo = $this->repoRow('alice/app', $alice, private: false);
        $uc = $this->updateSettingsUseCase();

        $uc($alice, 'alice/app', private: true);

        self::assertTrue($repo->isPrivate());
        $event = $this->singleEvent(RepoVisibilityChanged::class);
        self::assertSame($repo->id(), $event->repoId);
        self::assertTrue($event->isPrivate);
        self::assertSame($alice->id(), $event->actorId);

        // Re-applying the same value changes nothing and emits no new event.
        $uc($alice, 'alice/app', private: true);
        self::assertCount(1, $this->eventsOf(RepoVisibilityChanged::class));
    }

    public function testMetadataChangesEmitNoVisibilityEvent(): void
    {
        $alice = $this->user('alice');
        $repo = $this->repoRow('alice/app', $alice, private: false);

        ($this->updateSettingsUseCase())($alice, 'alice/app', description: 'New desc', projectName: 'App', hidden: true);

        self::assertSame('New desc', $repo->description());
        self::assertSame('App', $repo->projectName());
        self::assertTrue($repo->isHidden());
        self::assertFalse($repo->isPrivate());
        self::assertCount(0, $this->eventsOf(RepoVisibilityChanged::class));
    }
}

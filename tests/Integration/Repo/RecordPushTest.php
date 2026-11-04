<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repo;

use App\Repo\Application\PushChange;
use App\Repo\Domain\Event\BranchTagCreated;
use App\Repo\Domain\Event\BranchTagDeleted;
use App\Repo\Domain\Event\PushReceived;
use App\Shared\Domain\Exception\NotFound;

final class RecordPushTest extends RepoUseCaseTestCase
{
    private const string ZERO = '0000000000000000000000000000000000000000';
    private const string SHA_A = '1111111111111111111111111111111111111111';
    private const string SHA_B = '2222222222222222222222222222222222222222';
    private const string SHA_C = '3333333333333333333333333333333333333333';
    private const string SHA_D = '4444444444444444444444444444444444444444';

    public function testEmitsOnePushWithPerRefBranchTagEvents(): void
    {
        $alice = $this->user('alice');
        $repo = $this->repoRow('alice/app', $alice);

        $changes = [
            new PushChange('refs/heads/main', self::ZERO, self::SHA_A),   // create
            new PushChange('refs/heads/stale', self::SHA_B, self::ZERO),  // delete
            new PushChange('refs/heads/feat', self::SHA_C, self::SHA_D),  // update
        ];

        ($this->recordPushUseCase())('alice/app', $changes, $alice);

        $push = $this->singleEvent(PushReceived::class);
        self::assertSame($repo->id(), $push->repoId);
        self::assertSame([
            ['ref' => 'refs/heads/main', 'old' => self::ZERO, 'new' => self::SHA_A],
            ['ref' => 'refs/heads/stale', 'old' => self::SHA_B, 'new' => self::ZERO],
            ['ref' => 'refs/heads/feat', 'old' => self::SHA_C, 'new' => self::SHA_D],
        ], $push->changes);
        self::assertSame($alice->id(), $push->actorId);

        $created = $this->singleEvent(BranchTagCreated::class);
        self::assertSame('refs/heads/main', $created->ref);
        self::assertSame(self::SHA_A, $created->newSha);

        $deleted = $this->singleEvent(BranchTagDeleted::class);
        self::assertSame('refs/heads/stale', $deleted->ref);
        self::assertSame(self::SHA_B, $deleted->oldSha);
    }

    public function testEmitsExactlyOnePushEvenWithNoBranchTagChanges(): void
    {
        $alice = $this->user('alice');
        $this->repoRow('alice/app', $alice);

        ($this->recordPushUseCase())('alice/app', [
            new PushChange('refs/heads/main', self::SHA_C, self::SHA_D),
        ], $alice);

        self::assertCount(1, $this->eventsOf(PushReceived::class));
        self::assertCount(0, $this->eventsOf(BranchTagCreated::class));
        self::assertCount(0, $this->eventsOf(BranchTagDeleted::class));
    }

    public function testTouchesUpdatedAt(): void
    {
        $alice = $this->user('alice');
        $repo = $this->repoRow('alice/app', $alice);
        $this->clock->modify('+2 hours');

        ($this->recordPushUseCase())('alice/app', [], $alice);

        self::assertEquals($this->clock->now(), $repo->updatedAt());
        self::assertCount(1, $this->eventsOf(PushReceived::class));
    }

    public function testMissingRepoIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        ($this->recordPushUseCase())('ghost/repo', [], null);
    }
}

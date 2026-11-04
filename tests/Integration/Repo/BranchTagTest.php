<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repo;

use App\Repo\Domain\Event\BranchTagDeleted;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\NotFound;

final class BranchTagTest extends RepoUseCaseTestCase
{
    public function testCannotDeleteDefaultBranch(): void
    {
        $alice = $this->user('alice');
        $this->repoRow('alice/app', $alice);
        $this->initRepoOnDisk('alice/app');

        $this->expectException(Conflict::class);
        ($this->deleteBranchUseCase())($alice, 'alice/app', 'main');
    }

    public function testDeletesNonDefaultBranchAndEmitsEvent(): void
    {
        $alice = $this->user('alice');
        $this->repoRow('alice/app', $alice);
        $gitRepo = $this->initRepoOnDisk('alice/app');
        $this->writer->createBranch($gitRepo, 'feature');

        ($this->deleteBranchUseCase())($alice, 'alice/app', 'feature');

        $refs = $this->reader->getRefs($this->storage->open(RepoName::fromInput('alice/app')));
        self::assertNotContains('feature', $refs->branches);

        $event = $this->singleEvent(BranchTagDeleted::class);
        self::assertSame('refs/heads/feature', $event->ref);
        self::assertNotSame('', $event->oldSha);
        self::assertSame($alice->id(), $event->actorId);
    }

    public function testDeleteMissingBranchIsNotFound(): void
    {
        $alice = $this->user('alice');
        $this->repoRow('alice/app', $alice);
        $this->initRepoOnDisk('alice/app');

        $this->expectException(NotFound::class);
        ($this->deleteBranchUseCase())($alice, 'alice/app', 'ghost');
    }

    public function testSetDefaultBranchMovesHead(): void
    {
        $alice = $this->user('alice');
        $this->repoRow('alice/app', $alice);
        $gitRepo = $this->initRepoOnDisk('alice/app');
        $this->writer->createBranch($gitRepo, 'dev');

        ($this->setDefaultBranchUseCase())($alice, 'alice/app', 'dev');

        $refs = $this->reader->getRefs($this->storage->open(RepoName::fromInput('alice/app')));
        self::assertSame('dev', $refs->currentBranch);
    }

    public function testSetDefaultBranchMissingIsNotFound(): void
    {
        $alice = $this->user('alice');
        $this->repoRow('alice/app', $alice);
        $this->initRepoOnDisk('alice/app');

        $this->expectException(NotFound::class);
        ($this->setDefaultBranchUseCase())($alice, 'alice/app', 'ghost');
    }

    public function testDeletesTagAndEmitsEvent(): void
    {
        $alice = $this->user('alice');
        $this->repoRow('alice/app', $alice);
        $gitRepo = $this->initRepoOnDisk('alice/app');
        $this->writer->createTag($gitRepo, 'v1');

        ($this->deleteTagUseCase())($alice, 'alice/app', 'v1');

        $refs = $this->reader->getRefs($this->storage->open(RepoName::fromInput('alice/app')));
        self::assertNotContains('v1', $refs->tags);

        $event = $this->singleEvent(BranchTagDeleted::class);
        self::assertSame('refs/tags/v1', $event->ref);
        self::assertSame($alice->id(), $event->actorId);
    }

    public function testDeleteMissingTagIsNotFound(): void
    {
        $alice = $this->user('alice');
        $this->repoRow('alice/app', $alice);
        $this->initRepoOnDisk('alice/app');

        $this->expectException(NotFound::class);
        ($this->deleteTagUseCase())($alice, 'alice/app', 'ghost');
    }
}

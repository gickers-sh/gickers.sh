<?php

declare(strict_types=1);

namespace App\Tests\Integration\CodeIntel;

use App\CodeIntel\Application\Message\IndexCommit;
use App\CodeIntel\Application\PushIndexSubscriber;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\Event\PushReceived;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Covers the new async-indexing dispatch introduced for the PHP port: on a push, one
 * {@see IndexCommit} is scheduled per distinct non-deleted target commit (deletes and duplicate
 * shas skipped), and nothing happens for an unknown repository.
 */
final class PushIndexSubscriberTest extends TestCase
{
    private const string ZERO = '0000000000000000000000000000000000000000';

    public function testDispatchesOneIndexCommitPerDistinctNonDeleteSha(): void
    {
        $repo = $this->repo('demo');
        $repos = $this->createMock(RepoStore::class);
        $repos->method('byId')->willReturn($repo);
        $bus = $this->recordingBus();

        (new PushIndexSubscriber($repos, $bus))(new PushReceived(7, [
            ['ref' => 'refs/heads/main', 'old' => self::ZERO, 'new' => 'aaaaaa'],
            ['ref' => 'refs/heads/feat', 'old' => 'bbbbbb', 'new' => 'aaaaaa'], // duplicate sha → once
            ['ref' => 'refs/heads/stale', 'old' => 'cccccc', 'new' => self::ZERO], // delete → skipped
            ['ref' => 'refs/tags/v1', 'old' => self::ZERO, 'new' => 'dddddd'],
        ], null));

        self::assertCount(2, $bus->dispatched);
        self::assertContainsOnlyInstancesOf(IndexCommit::class, $bus->dispatched);
        self::assertSame('demo', $bus->dispatched[0]->repo);
        self::assertSame('aaaaaa', $bus->dispatched[0]->sha);
        self::assertSame('demo', $bus->dispatched[1]->repo);
        self::assertSame('dddddd', $bus->dispatched[1]->sha);
    }

    public function testDoesNothingForUnknownRepository(): void
    {
        $repos = $this->createMock(RepoStore::class);
        $repos->method('byId')->willReturn(null);
        $bus = $this->recordingBus();

        (new PushIndexSubscriber($repos, $bus))(new PushReceived(99, [
            ['ref' => 'refs/heads/main', 'old' => self::ZERO, 'new' => 'aaaaaa'],
        ], null));

        self::assertCount(0, $bus->dispatched);
    }

    private function repo(string $name): Repo
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');

        return new Repo(
            name: RepoName::fromInput($name),
            owner: new User('alice', false, $now),
            projectName: '',
            description: '',
            isPrivate: false,
            isHidden: false,
            isMirror: false,
            forkOf: null,
            createdAt: $now,
        );
    }

    /**
     * @return MessageBusInterface&object{dispatched: list<object>}
     */
    private function recordingBus(): MessageBusInterface
    {
        return new class implements MessageBusInterface {
            /** @var list<object> */
            public array $dispatched = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->dispatched[] = $message;

                return new Envelope($message);
            }
        };
    }
}

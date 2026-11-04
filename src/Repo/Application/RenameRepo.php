<?php

declare(strict_types=1);

namespace App\Repo\Application;

use App\Access\Application\AccessGuard;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\Event\RepoRenamed;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\NotFound;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Renames a repository (directory then row), rolling the directory back if the
 * row update fails. Requires read-write access, matching Go `rename.go`.
 */
final readonly class RenameRepo
{
    public function __construct(
        private RepoStore $repoStore,
        private AccessGuard $accessGuard,
        private BareRepositoryStorage $storage,
        private ClockInterface $clock,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(?User $actor, RepoName|string $name, RepoName|string $newName): Repo
    {
        $name = $name instanceof RepoName ? $name : RepoName::fromInput($name);
        $newName = $newName instanceof RepoName ? $newName : RepoName::fromInput($newName);

        $repo = $this->accessGuard->assertRepo($actor, $name, AccessLevel::ReadWriteAccess);
        if (null === $repo) {
            throw new NotFound('repository not found');
        }

        $oldName = $repo->nameString();

        if (null !== $this->repoStore->byName($newName) || $this->storage->exists($newName)) {
            throw new Conflict(sprintf('repository "%s" already exists', $newName));
        }

        $this->storage->rename($name, $newName);
        try {
            $repo->rename($newName, $this->clock->now());
            $this->repoStore->save($repo);
        } catch (UniqueConstraintViolationException) {
            $this->storage->rename($newName, $name);
            throw new Conflict(sprintf('repository "%s" already exists', $newName));
        } catch (\Throwable $e) {
            $this->storage->rename($newName, $name);
            throw $e;
        }

        $this->eventDispatcher->dispatch(new RepoRenamed($repo->id(), $oldName, $actor?->id()));

        return $repo;
    }
}

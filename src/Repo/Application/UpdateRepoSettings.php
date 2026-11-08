<?php

declare(strict_types=1);

namespace App\Repo\Application;

use App\Access\Application\AccessGuard;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\Event\RepoVisibilityChanged;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Shared\Domain\Exception\NotFound;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Updates repository metadata. Only the arguments passed as non-null are applied.
 *
 * {@see RepoVisibilityChanged} is emitted only when the private flag actually
 * flips; description, project name and hidden changes carry no domain event.
 * Requires read-write access.
 */
final readonly class UpdateRepoSettings
{
    public function __construct(
        private RepoStore $repoStore,
        private AccessGuard $accessGuard,
        private ClockInterface $clock,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(
        ?User $actor,
        RepoName|string $name,
        ?string $description = null,
        ?string $projectName = null,
        ?bool $private = null,
        ?bool $hidden = null,
    ): Repo {
        $name = $name instanceof RepoName ? $name : RepoName::fromInput($name);

        $repo = $this->accessGuard->assertRepo($actor, $name, AccessLevel::ReadWriteAccess);
        if (null === $repo) {
            throw new NotFound('repository not found');
        }

        $now = $this->clock->now();

        if (null !== $description) {
            $repo->describe($description, $now);
        }
        if (null !== $projectName) {
            $repo->changeProjectName($projectName, $now);
        }
        if (null !== $hidden) {
            $repo->setHidden($hidden, $now);
        }

        $visibilityChanged = null !== $private && $private !== $repo->isPrivate();
        if ($visibilityChanged) {
            $repo->setPrivate($private, $now);
        }

        $this->repoStore->save($repo);

        if ($visibilityChanged) {
            $this->eventDispatcher->dispatch(new RepoVisibilityChanged($repo->id(), $private, $actor?->id()));
        }

        return $repo;
    }
}

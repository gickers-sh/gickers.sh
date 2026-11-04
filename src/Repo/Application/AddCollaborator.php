<?php

declare(strict_types=1);

namespace App\Repo\Application;

use App\Access\Application\AccessGuard;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Domain\CollaboratorStore;
use App\Repo\Domain\Event\CollaboratorAdded;
use App\Repo\Domain\Model\Collaborator;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\NotFound;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Grants a user access to a repository.
 *
 * Deviation from Go: collaborator management requires repository admin access
 * (owner, admin collaborator or server admin). Go gates on read-write plus a
 * per-level grant bound (`pkg/ssh/cmd/collab.go`); the stricter admin gate drops
 * that nuance for a simpler, safer contract.
 */
final readonly class AddCollaborator
{
    public function __construct(
        private AccessGuard $accessGuard,
        private UserStore $userStore,
        private CollaboratorStore $collaboratorStore,
        private ClockInterface $clock,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(
        ?User $actor,
        RepoName|string $name,
        string $username,
        AccessLevel $level = AccessLevel::ReadWriteAccess,
    ): Collaborator {
        $name = $name instanceof RepoName ? $name : RepoName::fromInput($name);

        $repo = $this->accessGuard->assertRepo($actor, $name, AccessLevel::AdminAccess);
        if (null === $repo) {
            throw new NotFound('repository not found');
        }

        $user = $this->userStore->byUsername(strtolower(trim($username)));
        if (null === $user) {
            throw new NotFound(sprintf('user "%s" not found', $username));
        }

        if (null !== $this->collaboratorStore->find($repo, $user)) {
            throw new Conflict('collaborator already exists');
        }

        $collaborator = new Collaborator($repo, $user, $level, $this->clock->now());
        $this->collaboratorStore->save($collaborator);

        $this->eventDispatcher->dispatch(new CollaboratorAdded($repo->id(), $user->id(), $actor?->id()));

        return $collaborator;
    }
}

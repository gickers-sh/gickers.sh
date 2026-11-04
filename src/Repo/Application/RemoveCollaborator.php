<?php

declare(strict_types=1);

namespace App\Repo\Application;

use App\Access\Application\AccessGuard;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Domain\CollaboratorStore;
use App\Repo\Domain\Event\CollaboratorRemoved;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\Exception\NotFound;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Revokes a user's collaborator access to a repository. Requires repository
 * admin access (see {@see AddCollaborator} for the deviation from Go).
 */
final readonly class RemoveCollaborator
{
    public function __construct(
        private AccessGuard $accessGuard,
        private UserStore $userStore,
        private CollaboratorStore $collaboratorStore,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(?User $actor, RepoName|string $name, string $username): void
    {
        $name = $name instanceof RepoName ? $name : RepoName::fromInput($name);

        $repo = $this->accessGuard->assertRepo($actor, $name, AccessLevel::AdminAccess);
        if (null === $repo) {
            throw new NotFound('repository not found');
        }

        $user = $this->userStore->byUsername(strtolower(trim($username)));
        if (null === $user) {
            throw new NotFound(sprintf('user "%s" not found', $username));
        }

        $collaborator = $this->collaboratorStore->find($repo, $user);
        if (null === $collaborator) {
            throw new NotFound('collaborator not found');
        }

        $this->collaboratorStore->remove($collaborator);

        $this->eventDispatcher->dispatch(new CollaboratorRemoved($repo->id(), $user->id(), $actor?->id()));
    }
}

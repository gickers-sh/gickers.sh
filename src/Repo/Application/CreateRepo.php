<?php

declare(strict_types=1);

namespace App\Repo\Application;

use App\Access\Application\AccessResolver;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\Event\RepoCreated;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Git\HookInstaller;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\Conflict;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Creates an empty bare repository on disk and its database row.
 *
 * Gating mirrors Go `checkIfRepoCollab` (`pkg/ssh/cmd/cmd.go:251-256` / `RepoVoter::REPO_CREATE`):
 * the caller needs write access to the (as-yet unused) name. Existence is never
 * pre-checked against the row so a lost race surfaces as the unique-constraint
 * rollback below, exactly like Go's `claimRepoPath` + unique index.
 */
final readonly class CreateRepo
{
    public function __construct(
        private RepoStore $repoStore,
        private AccessResolver $accessResolver,
        private BareRepositoryStorage $storage,
        private HookInstaller $hookInstaller,
        private ClockInterface $clock,
        private EventDispatcherInterface $eventDispatcher,
        private RepoNamespacePolicy $namespacePolicy = new RepoNamespacePolicy(),
    ) {
    }

    public function __invoke(
        ?User $actor,
        RepoName|string $name,
        bool $private = false,
        bool $hidden = false,
        string $description = '',
        string $projectName = '',
    ): Repo {
        $name = $name instanceof RepoName ? $name : RepoName::fromInput($name);

        $existing = $this->repoStore->byName($name);
        if (!$this->accessResolver->levelFor($actor, $name, $existing)->canWrite()) {
            throw new AccessDenied('unauthorized');
        }
        // Deviation from Go: a repository must have an authenticated owner; Go
        // falls back to the default admin for anonymous callers, which we drop.
        if (null === $actor) {
            throw new AccessDenied('unauthorized');
        }
        $this->namespacePolicy->assertCanClaimName($actor, $name);
        if ($this->storage->exists($name)) {
            throw new Conflict(sprintf('repository "%s" already exists', $name));
        }

        $now = $this->clock->now();
        try {
            $gitRepo = $this->storage->init($name, 'main', $description);
            $this->hookInstaller->install($gitRepo);
            $repo = new Repo(
                name: $name,
                owner: $actor,
                projectName: $projectName,
                description: $description,
                isPrivate: $private,
                isHidden: $hidden,
                isMirror: false,
                forkOf: null,
                createdAt: $now,
            );
            $this->repoStore->save($repo);
        } catch (UniqueConstraintViolationException) {
            $this->storage->delete($name);
            throw new Conflict(sprintf('repository "%s" already exists', $name));
        } catch (\Throwable $e) {
            $this->storage->delete($name);
            throw $e;
        }

        $this->eventDispatcher->dispatch(new RepoCreated($repo->id(), $actor->id()));

        return $repo;
    }
}

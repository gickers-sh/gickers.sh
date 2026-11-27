<?php

declare(strict_types=1);

namespace App\Repo\Application;

use App\Access\Application\AccessGuard;
use App\Access\Application\AccessResolver;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\Event\RepoCreated;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Git\HookInstaller;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\NotFound;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Forks a repository: a cheap bare clone owned by the caller that records its
 * source as `forkOf`.
 *
 * Read access to the source is required (denyRead semantics). When no target is
 * given the fork lands under the caller's namespace using the source's base name,
 * matching Go `pkg/ssh/cmd/fork.go:34-54`.
 *
 * Deviation from Go: the fork inherits the source's privacy. Go leaves a fork
 * public unless `-p` is passed; the contract here takes no privacy flag, so
 * inheriting it prevents a private repository being exposed through a public fork.
 */
final readonly class ForkRepo
{
    public function __construct(
        private RepoStore $repoStore,
        private AccessGuard $accessGuard,
        private AccessResolver $accessResolver,
        private BareRepositoryStorage $storage,
        private HookInstaller $hookInstaller,
        private ClockInterface $clock,
        private EventDispatcherInterface $eventDispatcher,
        private RepoNamespacePolicy $namespacePolicy = new RepoNamespacePolicy(),
    ) {
    }

    public function __invoke(?User $actor, RepoName|string $source, RepoName|string|null $target = null): Repo
    {
        $source = $source instanceof RepoName ? $source : RepoName::fromInput($source);

        $src = $this->accessGuard->assertRepo($actor, $source, AccessLevel::ReadOnlyAccess);
        if (null === $src) {
            throw new NotFound('repository not found');
        }

        // A fork is owned by the caller, so it cannot be anonymous.
        if (null === $actor) {
            throw new AccessDenied('unauthorized');
        }

        if (null !== $target) {
            $target = $target instanceof RepoName ? $target : RepoName::fromInput($target);
        } else {
            $base = $source->value;
            if (false !== ($slash = strrpos($base, '/'))) {
                $base = substr($base, $slash + 1);
            }
            $target = RepoName::fromInput('' !== $actor->username() ? $actor->username().'/'.$base : $base);
        }

        $this->namespacePolicy->assertCanClaimName($actor, $target);

        if (!$this->accessResolver->levelFor($actor, $target, $this->repoStore->byName($target))->canWrite()) {
            throw new AccessDenied('unauthorized');
        }
        if ($this->storage->exists($target)) {
            throw new Conflict(sprintf('repository "%s" already exists', $target));
        }

        $now = $this->clock->now();
        try {
            $gitRepo = $this->storage->fork($source, $target);
            $this->hookInstaller->install($gitRepo);
            $repo = new Repo(
                name: $target,
                owner: $actor,
                projectName: '',
                description: '',
                isPrivate: $src->isPrivate(),
                isHidden: false,
                isMirror: false,
                forkOf: $src,
                createdAt: $now,
            );
            $this->repoStore->save($repo);
        } catch (UniqueConstraintViolationException) {
            $this->storage->delete($target);
            throw new Conflict(sprintf('repository "%s" already exists', $target));
        } catch (\Throwable $e) {
            $this->storage->delete($target);
            throw $e;
        }

        $this->eventDispatcher->dispatch(new RepoCreated($repo->id(), $actor->id()));

        return $repo;
    }
}

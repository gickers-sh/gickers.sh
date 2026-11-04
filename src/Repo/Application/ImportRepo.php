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
use App\Shared\Infrastructure\Security\SsrfGuard;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Imports a repository from a remote URL as a bare (optionally mirror) repository.
 *
 * The remote is validated against SSRF rules before anything else, so a blocked
 * host can never be probed through import timing. Gating and rollback match
 * {@see CreateRepo}.
 */
final readonly class ImportRepo
{
    public function __construct(
        private RepoStore $repoStore,
        private AccessResolver $accessResolver,
        private BareRepositoryStorage $storage,
        private HookInstaller $hookInstaller,
        private SsrfGuard $ssrfGuard,
        private ClockInterface $clock,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(
        ?User $actor,
        RepoName|string $name,
        string $remoteUrl,
        bool $mirror = false,
        bool $private = false,
        bool $hidden = false,
        string $description = '',
        string $projectName = '',
    ): Repo {
        $this->ssrfGuard->assertPublicUrl($remoteUrl);

        $name = $name instanceof RepoName ? $name : RepoName::fromInput($name);

        $existing = $this->repoStore->byName($name);
        if (!$this->accessResolver->levelFor($actor, $name, $existing)->canWrite()) {
            throw new AccessDenied('unauthorized');
        }
        if (null === $actor) {
            throw new AccessDenied('unauthorized');
        }
        if ($this->storage->exists($name)) {
            throw new Conflict(sprintf('repository "%s" already exists', $name));
        }

        $now = $this->clock->now();
        try {
            $gitRepo = $this->storage->importMirror($remoteUrl, $name, $mirror);
            $this->hookInstaller->install($gitRepo);
            $repo = new Repo(
                name: $name,
                owner: $actor,
                projectName: $projectName,
                description: $description,
                isPrivate: $private,
                isHidden: $hidden,
                isMirror: $mirror,
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

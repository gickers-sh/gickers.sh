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
use App\Shared\Infrastructure\Security\SsrfGuard;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Forks a repository: a cheap bare clone owned by the caller that records its
 * source as `forkOf` (or null if forked from an external URL).
 *
 * Supports local repositories on this server as well as external Git URLs
 * (https://, git://, ssh://, git@...). When no target is given, the fork lands
 * under the caller's namespace using the source's base name (<username>/<repo_name_source>).
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
        private ?SsrfGuard $ssrfGuard = null,
    ) {
    }

    public static function isExternalUrl(string $source): bool
    {
        $source = trim($source);

        return str_starts_with($source, 'http://')
            || str_starts_with($source, 'https://')
            || str_starts_with($source, 'git://')
            || str_starts_with($source, 'ssh://')
            || str_starts_with($source, 'git@')
            || str_contains($source, '://');
    }

    public static function extractRepoNameSource(string $source): string
    {
        $source = rtrim(trim($source), '/');
        // Handle scp-like syntax: git@github.com:owner/repo.git
        if (str_contains($source, ':') && !str_contains($source, '://')) {
            $parts = explode(':', $source, 2);
            $source = $parts[1] ?? $source;
        }

        $parsedPath = parse_url($source, PHP_URL_PATH);
        $path = is_string($parsedPath) && '' !== $parsedPath ? $parsedPath : $source;
        $slash = strrpos($path, '/');
        $base = false !== $slash ? substr($path, $slash + 1) : $path;

        if (str_ends_with(strtolower($base), '.git')) {
            $base = substr($base, 0, -4);
        }

        return $base;
    }

    public function __invoke(
        ?User $actor,
        RepoName|string $source,
        RepoName|string|null $target = null,
        bool $private = false,
        string $description = '',
        string $projectName = '',
        bool $hidden = false,
    ): Repo {
        // A fork is owned by the caller, so it cannot be anonymous.
        if (null === $actor) {
            throw new AccessDenied('unauthorized');
        }

        $sourceStr = $source instanceof RepoName ? $source->value : trim($source);
        $isExternal = self::isExternalUrl($sourceStr);
        $base = self::extractRepoNameSource($sourceStr);

        if (null !== $target && '' !== trim((string) ($target instanceof RepoName ? $target->value : $target))) {
            $rawTarget = trim((string) ($target instanceof RepoName ? $target->value : $target));
            if (!str_contains($rawTarget, '/') && '' !== $actor->username()) {
                $rawTarget = $actor->username().'/'.$rawTarget;
            }
            $target = RepoName::fromInput($rawTarget);
        } else {
            $targetStr = '' !== $actor->username() ? $actor->username().'/'.$base : $base;
            $target = RepoName::fromInput($targetStr);
        }

        $this->namespacePolicy->assertCanClaimName($actor, $target);

        if (!$this->accessResolver->levelFor($actor, $target, $this->repoStore->byName($target))->canWrite()) {
            throw new AccessDenied('unauthorized');
        }
        if ($this->storage->exists($target)) {
            throw new Conflict(sprintf('repository "%s" already exists', $target));
        }

        $now = $this->clock->now();

        if ($isExternal) {
            $this->ssrfGuard?->assertPublicUrl($sourceStr);
            try {
                $gitRepo = $this->storage->forkFromRemote($sourceStr, $target, $description);
                $this->hookInstaller->install($gitRepo);
                $repo = new Repo(
                    name: $target,
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
                $this->storage->delete($target);
                throw new Conflict(sprintf('repository "%s" already exists', $target));
            } catch (\Throwable $e) {
                $this->storage->delete($target);
                throw $e;
            }
        } else {
            $sourceName = $source instanceof RepoName ? $source : RepoName::fromInput($sourceStr);
            $src = $this->accessGuard->assertRepo($actor, $sourceName, AccessLevel::ReadOnlyAccess);
            if (null === $src) {
                throw new NotFound('repository not found');
            }

            try {
                $gitRepo = $this->storage->fork($sourceName, $target, $description);
                $this->hookInstaller->install($gitRepo);
                $repo = new Repo(
                    name: $target,
                    owner: $actor,
                    projectName: '' !== $projectName ? $projectName : $src->projectName(),
                    description: '' !== $description ? $description : $src->description(),
                    isPrivate: $private || $src->isPrivate(),
                    isHidden: $hidden,
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
        }

        $this->eventDispatcher->dispatch(new RepoCreated($repo->id(), $actor->id()));

        return $repo;
    }
}

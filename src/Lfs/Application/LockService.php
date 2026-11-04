<?php

declare(strict_types=1);

namespace App\Lfs\Application;

use App\Access\Application\AccessResolver;
use App\Identity\Domain\Model\User;
use App\Lfs\Domain\Exception\LockConflict;
use App\Lfs\Domain\LfsLockStore;
use App\Lfs\Domain\Model\LfsLock;
use App\Repo\Domain\Model\Repo;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Domain\Exception\NotFound;
use Psr\Clock\ClockInterface;

/**
 * Git LFS lock management (Go `pkg/web/git_lfs_locks.go` and `pkg/store/database/lfs.go`).
 *
 * Implements create, list, verify, and unlock (with REPO_ADMIN forced unlock).
 */
final readonly class LockService
{
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT = 100;

    public function __construct(
        private LfsLockStore $locks,
        private AccessResolver $accessResolver,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Creates a new lock on $path for $repo.
     *
     * @throws InvalidInput if path is empty
     * @throws LockConflict if path is already locked
     */
    public function create(User $user, Repo $repo, string $path, ?string $refName = null): LfsLock
    {
        $cleanPath = $this->sanitizePath($path);
        if ('' === $cleanPath) {
            throw new InvalidInput('path cannot be empty');
        }

        $existing = $this->locks->byPath($repo, $cleanPath);
        if (null !== $existing) {
            throw new LockConflict($existing);
        }

        $lock = new LfsLock(
            $repo,
            $user,
            $cleanPath,
            $refName,
            $this->clock->now(),
        );

        $this->locks->save($lock);

        return $lock;
    }

    /**
     * Lists locks for a repository, optionally filtered by $path or $id, or paged.
     *
     * @return array{locks: list<LfsLock>, next_cursor: ?string}
     */
    public function list(Repo $repo, ?string $path = null, ?int $id = null, int $cursor = 1, int $limit = self::DEFAULT_LIMIT): array
    {
        if (null !== $id && $id > 0) {
            $lock = $this->locks->byId($repo, $id);

            return [
                'locks' => null !== $lock ? [$lock] : [],
                'next_cursor' => null,
            ];
        }

        if (null !== $path && '' !== trim($path)) {
            $cleanPath = $this->sanitizePath($path);
            $lock = $this->locks->byPath($repo, $cleanPath);

            return [
                'locks' => null !== $lock ? [$lock] : [],
                'next_cursor' => null,
            ];
        }

        $page = $cursor <= 0 ? 1 : $cursor;
        $pageSize = $limit <= 0 ? self::DEFAULT_LIMIT : min($limit, self::MAX_LIMIT);

        $locks = $this->locks->page($repo, $page, $pageSize);
        $nextCursor = \count($locks) === $pageSize ? (string) ($page + 1) : null;

        return [
            'locks' => $locks,
            'next_cursor' => $nextCursor,
        ];
    }

    /**
     * Verifies locks partitioned into "ours" (owned by $user) and "theirs" (owned by others).
     *
     * @return array{ours: list<LfsLock>, theirs: list<LfsLock>, next_cursor: ?string}
     */
    public function verify(Repo $repo, ?User $user, int $cursor = 1, int $limit = self::DEFAULT_LIMIT): array
    {
        $page = $cursor <= 0 ? 1 : $cursor;
        $pageSize = $limit <= 0 ? self::DEFAULT_LIMIT : min($limit, self::MAX_LIMIT);

        $locks = $this->locks->page($repo, $page, $pageSize);
        $nextCursor = \count($locks) === $pageSize ? (string) ($page + 1) : null;

        $ours = [];
        $theirs = [];

        foreach ($locks as $lock) {
            if (null !== $user && $lock->owner()->id() === $user->id()) {
                $ours[] = $lock;
            } else {
                $theirs[] = $lock;
            }
        }

        return [
            'ours' => $ours,
            'theirs' => $theirs,
            'next_cursor' => $nextCursor,
        ];
    }

    /**
     * Unlocks a path. Requires ownership or REPO_ADMIN privilege when forced.
     *
     * @throws NotFound     if lock does not exist for this repository
     * @throws AccessDenied if not the owner or missing REPO_ADMIN for force unlock
     */
    public function unlock(User $user, Repo $repo, int $lockId, bool $force = false): LfsLock
    {
        $lock = $this->locks->byId($repo, $lockId);
        if (null === $lock) {
            throw new NotFound('lock not found');
        }

        if ($force) {
            // Task spec: force réservé à REPO_ADMIN
            $level = $this->accessResolver->levelFor($user, $repo->name(), $repo);
            if (!$level->isAdmin()) {
                throw new AccessDenied('admin access required for force delete');
            }
        } elseif ($lock->owner()->id() !== $user->id()) {
            throw new AccessDenied('lock belongs to another user');
        }

        $this->locks->remove($lock);

        return $lock;
    }

    private function sanitizePath(string $path): string
    {
        return ltrim(trim($path), '/');
    }
}

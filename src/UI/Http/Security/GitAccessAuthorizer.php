<?php

declare(strict_types=1);

namespace App\UI\Http\Security;

use App\Access\Application\AccessResolver;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;

/**
 * Decides what to do with a Git/LFS HTTP request, a faithful port of Go
 * `pkg/web/git.go` (`authorize`, `lfsNeedsWrite`, `isPushEndpoint`). The pure
 * {@see self::authorize()} is testable as a table (spec §3, Go `authorize_test.go`);
 * {@see self::decide()} resolves the repository and access level first, exactly
 * like Go `be.Readable`: `repo` is non-null only when the caller may read it, so a
 * private and a missing repository are indistinguishable downstream.
 */
final readonly class GitAccessAuthorizer
{
    public const UPLOAD_PACK = 'git-upload-pack';
    public const RECEIVE_PACK = 'git-receive-pack';
    public const UPLOAD_ARCHIVE = 'git-upload-archive';

    public function __construct(
        private AccessResolver $accessResolver,
        private RepoStore $repoStore,
    ) {
    }

    public function decide(
        ?User $user,
        RepoName $repo,
        string $service,
        string $file,
        string $method,
        bool $badCredentials,
    ): GitAuthorization {
        $entity = $this->repoStore->byName($repo);
        $level = $this->accessResolver->levelFor($user, $repo, $entity);
        $readable = null !== $entity && $level->value >= AccessLevel::ReadOnlyAccess->value;

        $verdict = self::authorize($service, $file, $method, $readable, $level, null === $user, $badCredentials);

        return new GitAuthorization($verdict, $readable ? $entity : null, $level);
    }

    /**
     * Pure authorization table. `$readable` is whether the caller may read an
     * existing repository; a private repository the caller cannot read and a
     * missing one both arrive as `false`, so they get the same verdict. Bad
     * credentials are rejected before a push can create anything.
     */
    public static function authorize(
        string $service,
        string $file,
        string $method,
        bool $readable,
        AccessLevel $level,
        bool $anonymous,
        bool $badCredentials,
    ): GitVerdict {
        $canWrite = $level->value >= AccessLevel::ReadWriteAccess->value;

        if (str_starts_with($file, 'info/lfs')) {
            if (!$readable) {
                return GitVerdict::DenyUnreadable;
            }
            if ($badCredentials) {
                return GitVerdict::Forbid;
            }
            if (self::lfsNeedsWrite($file, $method) && !$canWrite) {
                return GitVerdict::ForbidLfsWrite;
            }

            return GitVerdict::Serve;
        }

        if (self::RECEIVE_PACK === $service) {
            if ($badCredentials) {
                return GitVerdict::Forbid;
            }
            if (!$readable && $canWrite && self::isPushEndpoint($file)) {
                return GitVerdict::CreateThenServe;
            }
            if (!$readable) {
                return GitVerdict::DenyUnreadable;
            }
            if (!$canWrite && $anonymous) {
                return GitVerdict::Unauthorized;
            }
            if (!$canWrite) {
                return GitVerdict::Forbid;
            }

            return GitVerdict::Serve;
        }

        if ($badCredentials) {
            return GitVerdict::Forbid;
        }
        if (!$readable) {
            return GitVerdict::DenyUnreadable;
        }

        return GitVerdict::Serve;
    }

    /**
     * Whether an LFS request needs write access.
     *
     * @see https://github.com/git-lfs/git-lfs/blob/main/docs/api/locking.md
     */
    private static function lfsNeedsWrite(string $file, string $method): bool
    {
        if (str_starts_with($file, 'info/lfs/locks')) {
            return str_ends_with($file, 'lfs/locks')
                || str_ends_with($file, 'lfs/locks/verify')
                || (str_ends_with($file, '/unlock') && 'POST' === $method);
        }
        if (str_starts_with($file, 'info/lfs/objects/basic')) {
            return 'PUT' === $method;
        }

        return false;
    }

    /**
     * Whether a receive-pack request on $file may create the repository: only
     * the ref advertisement and the RPC itself.
     */
    private static function isPushEndpoint(string $file): bool
    {
        return 'info/refs' === $file || self::RECEIVE_PACK === $file;
    }
}

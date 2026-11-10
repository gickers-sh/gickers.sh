<?php

declare(strict_types=1);

namespace App\Repo\Application;

use App\Access\Application\AccessGuard;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\Release\ReleaseStore;
use App\Repo\Domain\RepoName;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Domain\Exception\NotFound;

/**
 * Uploads a release artifact for an existing tag. Requires write access, verifies
 * the tag exists, and refuses to overwrite an existing artifact. The artifact
 * bytes come from the caller (SSH stdin), never a server-side path.
 */
final readonly class UploadRelease
{
    public function __construct(
        private AccessGuard $accessGuard,
        private BareRepositoryStorage $storage,
        private GitRepositoryReader $reader,
        private ReleaseStore $releases,
    ) {
    }

    public function __invoke(?User $actor, RepoName|string $name, string $tag, string $filename, string $contents): void
    {
        $name = $name instanceof RepoName ? $name : RepoName::fromInput($name);

        $this->accessGuard->assertRepo($actor, $name, AccessLevel::ReadWriteAccess);

        if (!$this->storage->exists($name)) {
            throw new NotFound('repository not found');
        }

        $gitRepo = $this->storage->open($name);
        $refs = $this->reader->getRefs($gitRepo);
        if (!\in_array($tag, $refs->tags, true)) {
            throw new InvalidInput(sprintf('tag "%s" does not exist', $tag));
        }

        $this->releases->store($name, $tag, $filename, $contents);
    }
}

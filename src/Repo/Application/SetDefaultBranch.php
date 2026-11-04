<?php

declare(strict_types=1);

namespace App\Repo\Application;

use App\Access\Application\AccessGuard;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\RepoName;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Shared\Domain\Exception\NotFound;
use Symfony\Component\Process\Process;

/**
 * Points the repository's HEAD at an existing branch. Requires read-write access
 * (Go `pkg/ssh/cmd/branch.go:86` requires read-write on the set arm).
 *
 * The on-disk write goes through `git symbolic-ref` rather than a writer method:
 * the Git infrastructure contract is frozen for phases 3-6, so HEAD is updated
 * here instead of extending {@see \App\Repo\Infrastructure\Git\GitRepositoryWriter}.
 * No domain event exists for a default-branch change, so none is emitted.
 */
final readonly class SetDefaultBranch
{
    public function __construct(
        private AccessGuard $accessGuard,
        private BareRepositoryStorage $storage,
        private GitRepositoryReader $reader,
    ) {
    }

    public function __invoke(?User $actor, RepoName|string $name, string $branch): void
    {
        $name = $name instanceof RepoName ? $name : RepoName::fromInput($name);

        $repo = $this->accessGuard->assertRepo($actor, $name, AccessLevel::ReadWriteAccess);
        if (null === $repo) {
            throw new NotFound('repository not found');
        }

        $gitRepo = $this->storage->open($name);
        $refs = $this->reader->getRefs($gitRepo);

        if (!\in_array($branch, $refs->branches, true)) {
            throw new NotFound(sprintf('branch "%s" not found', $branch));
        }

        $process = new Process(['git', 'symbolic-ref', 'HEAD', 'refs/heads/'.$branch], $gitRepo->path);
        $process->mustRun();
    }
}

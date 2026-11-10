<?php

declare(strict_types=1);

namespace App\Repo\Domain\Release;

use App\Repo\Domain\RepoName;

/**
 * Storage port for release artifacts. Keeps the Application and UI layers off the
 * concrete filesystem adapter, mirroring {@see \App\Repo\Domain\RepoDirectory}.
 */
interface ReleaseStore
{
    public function exists(RepoName $repo, string $tag, string $filename): bool;

    public function store(RepoName $repo, string $tag, string $filename, string $contents): void;

    /**
     * Absolute path of an existing artifact, or null when the tag/filename is unsafe
     * or the artifact does not exist.
     */
    public function pathFor(RepoName $repo, string $tag, string $filename): ?string;
}

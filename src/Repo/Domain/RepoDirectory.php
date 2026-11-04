<?php

declare(strict_types=1);

namespace App\Repo\Domain;

/**
 * On-disk presence of bare repositories under the data directory.
 */
interface RepoDirectory
{
    public function exists(RepoName $name): bool;

    /** Absolute path of `<repos>/<name>.git`, whether it exists or not. */
    public function pathFor(RepoName $name): string;
}

<?php

declare(strict_types=1);

namespace App\Access\Domain;

/**
 * Security attributes understood by the voters (`isGranted(Permission::REPO_READ, $repoName)`).
 */
final class Permission
{
    public const REPO_READ = 'repo.read';
    public const REPO_WRITE = 'repo.write';
    public const REPO_ADMIN = 'repo.admin';
    /** Create a repository that does not exist yet (Go `checkIfRepoCollab` on a missing repo). */
    public const REPO_CREATE = 'repo.create';
    public const SERVER_ADMIN = 'server.admin';

    private function __construct()
    {
    }
}

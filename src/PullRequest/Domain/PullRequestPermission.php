<?php

declare(strict_types=1);

namespace App\PullRequest\Domain;

/**
 * Security attributes understood by {@see \App\PullRequest\Infrastructure\Security\PullRequestVoter}
 * (`isGranted(PullRequestPermission::PR_MERGE, $pullRequest)`).
 */
final class PullRequestPermission
{
    /** Read a pull request (repository read access). */
    public const PR_VIEW = 'pr.view';
    /** Edit title/description (author or repository write access). */
    public const PR_EDIT = 'pr.edit';
    /** Close an open pull request (author or repository write access). */
    public const PR_CLOSE = 'pr.close';
    /** Reopen a closed pull request (author or repository write access). */
    public const PR_REOPEN = 'pr.reopen';
    /** Permanently delete a pull request (author or repository write access). */
    public const PR_DELETE = 'pr.delete';
    /** Merge a pull request (repository write access). */
    public const PR_MERGE = 'pr.merge';

    private function __construct() {}
}

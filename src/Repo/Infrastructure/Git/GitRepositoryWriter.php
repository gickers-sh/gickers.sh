<?php

declare(strict_types=1);

namespace App\Repo\Infrastructure\Git;

use App\Repo\Domain\Git\GitRepository;

final class GitRepositoryWriter
{
    /**
     * Creates a new branch pointing to startPoint (default HEAD).
     */
    public function createBranch(GitRepository $repo, string $branchName, string $startPoint = 'HEAD'): void
    {
        $sha = $this->resolveCommitSha($repo, $startPoint);
        if (null === $sha) {
            throw new \RuntimeException(sprintf('Cannot resolve start point "%s".', $startPoint));
        }

        $refName = 'refs/heads/'.$branchName;
        $check = GitCommand::run($repo->path, ['show-ref', '--verify', new Revision($refName)]);
        if ($check->isSuccessful()) {
            throw new \RuntimeException(sprintf('Branch "%s" already exists.', $branchName));
        }

        GitCommand::mustRun($repo->path, ['update-ref', new Revision($refName), $sha]);
    }

    /**
     * Deletes a branch.
     */
    public function deleteBranch(GitRepository $repo, string $branchName): void
    {
        $refName = str_starts_with($branchName, 'refs/heads/') ? $branchName : 'refs/heads/'.$branchName;
        $process = GitCommand::run($repo->path, ['update-ref', '-d', new Revision($refName)]);
        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf('Failed to delete branch "%s": %s', $branchName, $process->getErrorOutput()));
        }
    }

    /**
     * Creates a lightweight or annotated tag.
     */
    public function createTag(
        GitRepository $repo,
        string $tagName,
        string $target = 'HEAD',
        ?string $message = null,
        string $authorName = 'gickers.sh',
        string $authorEmail = 'noreply@gickers.sh',
    ): void {
        if (null !== $message) {
            $env = [
                'GIT_TAGGER_NAME' => $authorName,
                'GIT_TAGGER_EMAIL' => $authorEmail,
            ];
            GitCommand::mustRun($repo->path, ['tag', '-a', '-m', $message, new Revision($tagName), new Revision($target)], null, $env);
        } else {
            GitCommand::mustRun($repo->path, ['tag', new Revision($tagName), new Revision($target)]);
        }
    }

    /**
     * Deletes a tag.
     */
    public function deleteTag(GitRepository $repo, string $tagName): void
    {
        $process = GitCommand::run($repo->path, ['tag', '-d', new Revision($tagName)]);
        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf('Failed to delete tag "%s": %s', $tagName, $process->getErrorOutput()));
        }
    }

    /**
     * Resolves a branch name, ref, or hash to a 40-character commit SHA.
     */
    private function resolveCommitSha(GitRepository $repo, string $refOrSha): ?string
    {
        $rev = new Revision($refOrSha);
        $candidates = [
            $rev,
            new Revision('refs/heads/'.$rev->value),
            new Revision('refs/tags/'.$rev->value),
            new Revision($rev->value.'^{commit}'),
        ];

        foreach ($candidates as $cand) {
            $process = GitCommand::run($repo->path, ['rev-parse', '--verify', $cand]);
            if ($process->isSuccessful()) {
                $sha = trim($process->getOutput());
                if ('' !== $sha) {
                    return $sha;
                }
            }
        }

        return null;
    }
}

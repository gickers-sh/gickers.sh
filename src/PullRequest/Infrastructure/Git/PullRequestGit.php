<?php

declare(strict_types=1);

namespace App\PullRequest\Infrastructure\Git;

use App\PullRequest\Domain\MergeStrategy;
use App\PullRequest\Domain\Model\PullRequest;
use App\Repo\Domain\Git\Commit;
use App\Repo\Domain\Git\Diff;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoDirectory;
use App\Repo\Infrastructure\Git\GitCommand;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Repo\Infrastructure\Git\GitRepositoryWriter;
use App\Repo\Infrastructure\Git\Revision;
use App\Shared\Domain\Exception\InvalidInput;
use Symfony\Component\Process\Process;

/**
 * Git plumbing specific to pull requests: materialising `refs/pull/<n>/head`,
 * resolving branch tips, computing the three-dot diff and running the merge.
 *
 * Direct port of Go `git/pr.go` (`FetchPullRef`, `PRDiff`, `MergePR`). The merge
 * itself is delegated to {@see GitRepositoryWriter::merge} so the single git
 * writer owns every ref mutation.
 */
final class PullRequestGit
{
    public function __construct(
        private readonly RepoDirectory $repoDirectory,
        private readonly GitRepositoryReader $reader,
        private readonly GitRepositoryWriter $writer,
    ) {
    }

    /**
     * Resolves `refs/heads/<branch>` of a repository to its commit SHA, or null
     * when the branch does not exist.
     */
    public function resolveBranchCommit(Repo $repo, string $branch): ?string
    {
        return $this->revParse($this->repoDirectory->pathFor($repo->name()), 'refs/heads/'.$branch);
    }

    /**
     * The repository's default branch (its HEAD symbolic ref), e.g. `main`.
     */
    public function defaultBranch(Repo $repo): string
    {
        return $this->reader->openRepository($this->repoDirectory->pathFor($repo->name()))->defaultBranch;
    }

    /**
     * Materialises the head branch as `refs/pull/<number>/head` inside the target
     * repository and returns its commit SHA. Intra-repo updates the ref directly;
     * cross-repo fetches it with `git fetch --force` (Go `FetchPullRef`).
     */
    public function fetchPullRef(Repo $target, Repo $head, string $headRef, int $number): string
    {
        $targetPath = $this->repoDirectory->pathFor($target->name());
        $targetPullRef = \sprintf('refs/pull/%d/head', $number);
        $headRefClean = trim($headRef);
        if (!str_starts_with($headRefClean, 'refs/')) {
            $headRefClean = 'refs/heads/'.$headRefClean;
        }

        $headPath = $this->repoDirectory->pathFor($head->name());
        if ($headPath === $targetPath) {
            $headSha = $this->revParse($targetPath, $headRefClean);
            if (null === $headSha) {
                throw new InvalidInput(\sprintf('head branch %s does not exist', $headRef));
            }
            GitCommand::mustRun($targetPath, ['update-ref', new Revision($targetPullRef), $headSha]);

            return $headSha;
        }

        $fetch = GitCommand::run(
            $targetPath,
            ['fetch', '--force', $headPath, new Revision(\sprintf('%s:%s', $headRefClean, $targetPullRef))],
        );
        if (!$fetch->isSuccessful()) {
            throw new InvalidInput(\sprintf('head branch %s does not exist', $headRef));
        }

        $sha = $this->revParse($targetPath, $targetPullRef);
        if (null === $sha) {
            throw new InvalidInput(\sprintf('head branch %s does not exist', $headRef));
        }

        return $sha;
    }

    /**
     * Three-dot diff between the pull request base branch and its head ref, as
     * Go `PRDiff` (`git diff base...refs/pull/<n>/head`). Falls back to the stored
     * base/head commits when the pull ref is missing.
     */
    public function diff(Repo $target, PullRequest $pr): string
    {
        $path = $this->repoDirectory->pathFor($target->name());
        $pullRef = \sprintf('refs/pull/%d/head', $pr->number());
        $baseRef = 'refs/heads/'.$pr->baseRef();

        $spec = $this->revParse($path, $pullRef)
            ? \sprintf('%s...%s', $baseRef, $pullRef)
            : \sprintf('%s...%s', $pr->baseCommit(), $pr->headCommit());

        $process = GitCommand::run($path, ['diff', new Revision($spec)]);
        if (!$process->isSuccessful()) {
            return '';
        }

        return $process->getOutput();
    }

    /**
     * Starts a non-blocking three-dot diff process for the pull request.
     */
    public function startDiffProcess(Repo $target, PullRequest $pr): Process
    {
        $path = $this->repoDirectory->pathFor($target->name());
        $pullRef = \sprintf('refs/pull/%d/head', $pr->number());
        $baseRef = 'refs/heads/'.$pr->baseRef();

        $spec = $this->revParse($path, $pullRef)
            ? \sprintf('%s...%s', $baseRef, $pullRef)
            : \sprintf('%s...%s', $pr->baseCommit(), $pr->headCommit());

        return GitCommand::start($path, ['diff', new Revision($spec)]);
    }

    /**
     * Structured diff for a pull request.
     */
    public function parsedDiff(Repo $target, PullRequest $pr): Diff
    {
        return $this->reader->parseDiffOutput($this->diff($target, $pr));
    }

    /**
     * Lists all commits in the pull request (base..head).
     *
     * @return list<Commit>
     */
    public function commits(Repo $target, PullRequest $pr): array
    {
        $path = $this->repoDirectory->pathFor($target->name());
        $pullRef = \sprintf('refs/pull/%d/head', $pr->number());
        $baseRef = 'refs/heads/'.$pr->baseRef();

        $baseSha = $this->revParse($path, $baseRef) ?? $pr->baseCommit();
        $headSha = $this->revParse($path, $pullRef) ?? $pr->headCommit();
        if ('' === $baseSha || '' === $headSha) {
            return [];
        }

        $format = '%H%x00%h%x00%an%x00%ae%x00%at%x00%s%x00%b';
        $process = GitCommand::run($path, [
            'log',
            '-z',
            '--format=format:'.$format,
            new Revision(\sprintf('%s..%s', $baseSha, $headSha)),
        ]);
        if (!$process->isSuccessful()) {
            return [];
        }

        return $this->reader->parseCommitsOutput($process->getOutput());
    }

    /**
     * Starts a non-blocking `git log` process for the commits in the pull request.
     */
    public function startCommitsProcess(Repo $target, PullRequest $pr): ?Process
    {
        $path = $this->repoDirectory->pathFor($target->name());
        $pullRef = \sprintf('refs/pull/%d/head', $pr->number());
        $baseRef = 'refs/heads/'.$pr->baseRef();

        $baseSha = $this->revParse($path, $baseRef) ?? $pr->baseCommit();
        $headSha = $this->revParse($path, $pullRef) ?? $pr->headCommit();
        if ('' === $baseSha || '' === $headSha) {
            return null;
        }

        $format = '%H%x00%h%x00%an%x00%ae%x00%at%x00%s%x00%b';

        return GitCommand::start($path, [
            'log',
            '-z',
            '--format=format:'.$format,
            new Revision(\sprintf('%s..%s', $baseSha, $headSha)),
        ]);
    }

    /**
     * Runs the merge of `refs/pull/<number>/head` into the base branch with the
     * chosen strategy and returns the resulting commit SHA (Go `MergePR`).
     */
    public function merge(
        Repo $target,
        string $baseRef,
        int $number,
        MergeStrategy $strategy,
        string $message,
        string $authorName,
        string $authorEmail,
    ): string {
        $gitRepo = $this->reader->openRepository($this->repoDirectory->pathFor($target->name()));

        return $this->writer->merge(
            $gitRepo,
            $baseRef,
            \sprintf('refs/pull/%d/head', $number),
            $strategy->value,
            $message,
            $authorName,
            $authorEmail,
        );
    }

    public function canMerge(PullRequest $pr): bool
    {
        try {
            $gitRepo = $this->reader->openRepository($this->repoDirectory->pathFor($pr->repo()->name()));
            $pullRef = \sprintf('refs/pull/%d/head', $pr->number());

            return $this->writer->canMerge($gitRepo, $pr->baseRef(), $pullRef);
        } catch (\Throwable) {
            return false;
        }
    }

    private function revParse(string $repoPath, string $ref): ?string
    {
        $process = GitCommand::run($repoPath, ['rev-parse', '--verify', '--quiet', new Revision($ref.'^{commit}')]);
        if (!$process->isSuccessful()) {
            return null;
        }

        $sha = trim($process->getOutput());

        return '' === $sha ? null : $sha;
    }
}

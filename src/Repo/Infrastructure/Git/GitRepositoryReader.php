<?php

declare(strict_types=1);

namespace App\Repo\Infrastructure\Git;

use App\Repo\Domain\Git\Commit;
use App\Repo\Domain\Git\Diff;
use App\Repo\Domain\Git\DiffFile;
use App\Repo\Domain\Git\GitRepository;
use App\Repo\Domain\Git\Refs;
use App\Repo\Domain\Git\TreeEntry;
use Symfony\Component\Process\Process;

final class GitRepositoryReader
{
    /**
     * Open a git repository from the given path.
     * Validates directory, detects bare or non-bare repository,
     * reads description from .git/description or description file,
     * and resolves HEAD branch.
     */
    public function openRepository(string $path): GitRepository
    {
        $realPath = realpath($path);
        if (false === $realPath || !is_dir($realPath)) {
            throw new \RuntimeException(sprintf('Path "%s" does not exist or is not a directory.', $path));
        }

        $isBare = false;
        $gitDir = $realPath.'/.git';

        if (is_dir($gitDir)) {
            $isBare = false;
        } elseif (is_file($realPath.'/HEAD') && (is_dir($realPath.'/objects') || is_dir($realPath.'/refs'))) {
            $isBare = true;
            $gitDir = $realPath;
        } else {
            // Verify via git command if inside a working tree or bare repo
            $process = new Process(['git', 'rev-parse', '--is-bare-repository'], $realPath);
            $process->run();
            if (!$process->isSuccessful()) {
                throw new \RuntimeException(sprintf('Path "%s" is not a valid git repository.', $path));
            }
            $isBare = 'true' === trim($process->getOutput());
            $gitDir = $isBare ? $realPath : $realPath.'/.git';
        }

        $name = basename($realPath);
        if (str_ends_with($name, '.git')) {
            $name = substr($name, 0, -4);
        }

        // Description
        $description = '';
        $descFile = $gitDir.'/description';
        if (is_file($descFile)) {
            $descContent = trim((string) file_get_contents($descFile));
            // Default git description is "Unnamed repository; edit this file 'description' to name the repository."
            if (!str_starts_with($descContent, 'Unnamed repository;')) {
                $description = $descContent;
            }
        }

        // Default / HEAD branch
        $defaultBranch = 'main';
        $headProcess = new Process(['git', 'symbolic-ref', '--short', 'HEAD'], $realPath);
        $headProcess->run();
        if ($headProcess->isSuccessful()) {
            $head = trim($headProcess->getOutput());
            if ('' !== $head) {
                $defaultBranch = $head;
            }
        } else {
            // Fallback: rev-parse abbrev-ref HEAD
            $headProcess = new Process(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], $realPath);
            $headProcess->run();
            if ($headProcess->isSuccessful()) {
                $head = trim($headProcess->getOutput());
                if ('' !== $head && 'HEAD' !== $head) {
                    $defaultBranch = $head;
                }
            }
        }

        // Latest commit time / updatedAt
        $updatedAt = null;
        $timeProcess = new Process(['git', 'log', '-1', '--format=%ct', 'HEAD'], $realPath);
        $timeProcess->run();
        if ($timeProcess->isSuccessful()) {
            $ct = trim($timeProcess->getOutput());
            if ('' !== $ct && ctype_digit($ct)) {
                $updatedAt = (new \DateTimeImmutable())->setTimestamp((int) $ct);
            }
        }
        if (null === $updatedAt && is_dir($realPath)) {
            $mtime = @filemtime($realPath);
            if (false !== $mtime) {
                $updatedAt = (new \DateTimeImmutable())->setTimestamp($mtime);
            }
        }

        // Project name (read from git config if present)
        $projectName = null;
        $pnameProcess = new Process(['git', 'config', 'codes.project-name'], $realPath);
        $pnameProcess->run();
        if ($pnameProcess->isSuccessful()) {
            $pname = trim($pnameProcess->getOutput());
            if ('' !== $pname) {
                $projectName = $pname;
            }
        }

        return new GitRepository(
            name: $name,
            path: $realPath,
            isBare: $isBare,
            description: $description,
            defaultBranch: $defaultBranch,
            isPrivate: false,
            projectName: $projectName,
            updatedAt: $updatedAt,
        );
    }

    /**
     * Get tree entries using `git ls-tree -l -z {$ref} -- {$subpath}`.
     * Entry format: `<mode> <type> <hash> <size>\t<path>\0`.
     *
     * @return TreeEntry[]
     */
    public function getTree(GitRepository $repo, string $ref = 'HEAD', string $subpath = ''): array
    {
        $args = ['git', 'ls-tree', '-l', '-z', $ref];
        if ('' !== $subpath) {
            $args[] = '--';
            $args[] = $subpath;
        }

        $process = new Process($args, $repo->path);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf('Failed to get tree for ref "%s": %s', $ref, $process->getErrorOutput()));
        }

        $output = $process->getOutput();
        if ('' === $output) {
            return [];
        }

        $rawEntries = explode("\0", $output);
        $entries = [];

        foreach ($rawEntries as $rawEntry) {
            if ('' === $rawEntry) {
                continue;
            }

            // Tab separates metadata from relative path
            $tabPos = strpos($rawEntry, "\t");
            if (false === $tabPos) {
                continue;
            }

            $meta = substr($rawEntry, 0, $tabPos);
            $entryPath = substr($rawEntry, $tabPos + 1);

            // <mode> <type> <hash> <size>
            // Note: size can be multiple spaces padded or "-" for trees
            $parts = preg_split('/\s+/', trim($meta));
            if (false === $parts || count($parts) < 4) {
                continue;
            }

            $mode = $parts[0];
            $type = $parts[1];
            $hash = $parts[2];
            $sizeRaw = $parts[3];
            $size = ('-' === $sizeRaw || !is_numeric($sizeRaw)) ? null : (int) $sizeRaw;

            $name = basename($entryPath);

            $entries[] = new TreeEntry(
                name: $name,
                path: $entryPath,
                mode: $mode,
                type: $type,
                size: $size,
                hash: $hash,
            );
        }

        return $entries;
    }

    /**
     * Get blob content using `git cat-file -p {$ref}:{$path}`.
     */
    public function getBlob(GitRepository $repo, string $ref, string $path): string
    {
        $target = sprintf('%s:%s', $ref, ltrim($path, '/'));
        $process = new Process(['git', 'cat-file', '-p', $target], $repo->path);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf('Failed to get blob "%s": %s', $target, $process->getErrorOutput()));
        }

        return $process->getOutput();
    }

    /**
     * Finds and reads the README file at the root of the repository.
     * Looks for README.md, README, readme.markdown, README.txt (case-insensitive).
     *
     * @return array{path: string, content: string}|null
     */
    public function readme(GitRepository $repo, string $ref = 'HEAD'): ?array
    {
        try {
            $entries = $this->getTree($repo, $ref);
        } catch (\Throwable) {
            return null;
        }

        $candidates = ['readme.md', 'readme', 'readme.markdown', 'readme.txt'];
        $filesByName = [];
        foreach ($entries as $entry) {
            if ($entry->isFile()) {
                $filesByName[strtolower($entry->name)] = $entry;
            }
        }

        foreach ($candidates as $candidate) {
            if (isset($filesByName[$candidate])) {
                $entry = $filesByName[$candidate];
                try {
                    $content = $this->getBlob($repo, $ref, $entry->path);

                    return [
                        'path' => $entry->path,
                        'content' => $content,
                    ];
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * Backward-compatible alias for readme().
     *
     * @return array{path: string, content: string}|null
     */
    public function getReadme(GitRepository $repo, string $ref = 'HEAD'): ?array
    {
        return $this->readme($repo, $ref);
    }

    /**
     * Get commits using `git log -z --format=format:"%H%x00%h%x00%an%x00%ae%x00%at%x00%s%x00%b" -n {$limit} --skip={$offset} {$ref}`.
     *
     * @return Commit[]
     */
    public function getCommits(GitRepository $repo, string $ref = 'HEAD', int $limit = 50, int $offset = 0): array
    {
        $format = '%H%x00%h%x00%an%x00%ae%x00%at%x00%s%x00%b';
        $args = [
            'git',
            'log',
            '-z',
            '--format=format:'.$format,
            '-n',
            (string) $limit,
            '--skip='.(string) $offset,
            $ref,
        ];

        $process = new Process($args, $repo->path);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf('Failed to get commits for ref "%s": %s', $ref, $process->getErrorOutput()));
        }

        $output = $process->getOutput();
        if ('' === $output) {
            return [];
        }

        $parts = explode("\0", $output);
        $commits = [];
        $totalParts = count($parts);

        for ($i = 0; $i + 6 < $totalParts; $i += 7) {
            $hash = $parts[$i];
            if ('' === $hash) {
                break;
            }
            $shortHash = $parts[$i + 1] ?? substr($hash, 0, 7);
            $authorName = $parts[$i + 2] ?? '';
            $authorEmail = $parts[$i + 3] ?? '';
            $timestamp = (int) ($parts[$i + 4] ?? 0);
            $subject = $parts[$i + 5] ?? '';
            $body = trim($parts[$i + 6] ?? '');

            $commits[] = new Commit(
                hash: $hash,
                shortHash: $shortHash,
                authorName: $authorName,
                authorEmail: $authorEmail,
                timestamp: $timestamp,
                subject: $subject,
                body: $body,
            );
        }

        return $commits;
    }

    /**
     * Get diff using `git diff` or `git show`.
     * If refB is provided: `git diff {$refA}..{$refB}`
     * If refB is null:
     *   Checks if refA is a commit/ref. If single commit show: `git show --patch {$refA}`
     *   Otherwise: `git diff {$refA}`.
     */
    public function getDiff(GitRepository $repo, string $refA, ?string $refB = null): Diff
    {
        if (null !== $refB && '' !== $refB) {
            $args = ['git', 'diff', sprintf('%s..%s', $refA, $refB)];
        } else {
            $args = ['git', 'show', '--patch', '--format=', $refA];
            $testProcess = new Process($args, $repo->path);
            $testProcess->run();
            if ($testProcess->isSuccessful() && '' !== $testProcess->getOutput()) {
                return $this->parseDiffOutput($testProcess->getOutput());
            }

            // Fallback to git diff $refA
            $args = ['git', 'diff', $refA];
        }

        $process = new Process($args, $repo->path);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf('Failed to get diff: %s', $process->getErrorOutput()));
        }

        return $this->parseDiffOutput($process->getOutput());
    }

    /**
     * Get repository branches, current branch, and tags.
     */
    public function getRefs(GitRepository $repo): Refs
    {
        // Branches
        $branchProcess = new Process(['git', 'branch', '--list', '--format=%(refname:short)'], $repo->path);
        $branchProcess->run();
        $branches = [];
        if ($branchProcess->isSuccessful()) {
            $raw = trim($branchProcess->getOutput());
            if ('' !== $raw) {
                $branches = array_filter(array_map('trim', explode("\n", $raw)), static fn ($b) => '' !== $b);
                $branches = array_values($branches);
            }
        }

        // Current branch
        $currentBranch = $repo->defaultBranch;
        $currProcess = new Process(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], $repo->path);
        $currProcess->run();
        if ($currProcess->isSuccessful()) {
            $curr = trim($currProcess->getOutput());
            if ('' !== $curr && 'HEAD' !== $curr) {
                $currentBranch = $curr;
            }
        }

        // Tags
        $tagProcess = new Process(['git', 'tag', '--list'], $repo->path);
        $tagProcess->run();
        $tags = [];
        if ($tagProcess->isSuccessful()) {
            $raw = trim($tagProcess->getOutput());
            if ('' !== $raw) {
                $tags = array_filter(array_map('trim', explode("\n", $raw)), static fn ($t) => '' !== $t);
                $tags = array_values($tags);
            }
        }

        return new Refs(
            branches: $branches,
            currentBranch: $currentBranch,
            tags: $tags,
        );
    }

    /**
     * Run git blame on a file and return formatted blame lines.
     *
     * @return string[]
     */
    public function getBlame(GitRepository $repo, string $ref, string $path): array
    {
        $process = new Process(['git', 'blame', '--date=short', $ref, '--', ltrim($path, '/')], $repo->path);
        $process->run();
        if (!$process->isSuccessful()) {
            return [];
        }
        $output = trim($process->getOutput());
        if ('' === $output) {
            return [];
        }

        return explode("\n", $output);
    }

    /**
     * Parse raw diff output into a structured Diff.
     */
    private function parseDiffOutput(string $rawDiff): Diff
    {
        if ('' === trim($rawDiff)) {
            return new Diff(
                files: [],
                totalAdditions: 0,
                totalDeletions: 0,
                rawDiff: $rawDiff,
            );
        }

        $files = [];
        $totalAdditions = 0;
        $totalDeletions = 0;

        // Split by "diff --git "
        $hunks = preg_split('/(?=diff --git )/', $rawDiff, -1, \PREG_SPLIT_NO_EMPTY);
        if (false === $hunks) {
            return new Diff([], 0, 0, $rawDiff);
        }

        foreach ($hunks as $hunk) {
            $lines = explode("\n", $hunk);
            $firstLine = $lines[0] ?? '';

            // Example: diff --git a/path/to/file b/path/to/file
            $oldPath = '';
            $newPath = '';
            if (preg_match('#^diff --git a/(.*?) b/(.*)$#', $firstLine, $matches)) {
                $oldPath = $matches[1];
                $newPath = $matches[2];
            }

            $status = 'M';
            $fileAdditions = 0;
            $fileDeletions = 0;

            foreach ($lines as $line) {
                if (str_starts_with($line, 'new file mode')) {
                    $status = 'A';
                } elseif (str_starts_with($line, 'deleted file mode')) {
                    $status = 'D';
                } elseif (str_starts_with($line, '+') && !str_starts_with($line, '+++')) {
                    ++$fileAdditions;
                } elseif (str_starts_with($line, '-') && !str_starts_with($line, '---')) {
                    ++$fileDeletions;
                }
            }

            if ('' === $oldPath && '' === $newPath) {
                $oldPath = 'unknown';
                $newPath = 'unknown';
            }

            $totalAdditions += $fileAdditions;
            $totalDeletions += $fileDeletions;

            $files[] = new DiffFile(
                oldPath: $oldPath,
                newPath: $newPath,
                status: $status,
                patch: $hunk,
                additions: $fileAdditions,
                deletions: $fileDeletions,
            );
        }

        return new Diff(
            files: $files,
            totalAdditions: $totalAdditions,
            totalDeletions: $totalDeletions,
            rawDiff: $rawDiff,
        );
    }
}

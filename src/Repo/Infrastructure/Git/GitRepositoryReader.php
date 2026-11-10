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
            $process = GitCommand::run($realPath, ['rev-parse', '--is-bare-repository']);
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

        // Default / HEAD branch: read $gitDir/HEAD directly when it is a standard symbolic ref
        $defaultBranch = 'main';
        $headFile = $gitDir.'/HEAD';
        $headResolved = false;
        if (is_file($headFile)) {
            $headRaw = trim((string) file_get_contents($headFile));
            if (str_starts_with($headRaw, 'ref: refs/heads/')) {
                $branch = substr($headRaw, \strlen('ref: refs/heads/'));
                if ('' !== $branch) {
                    $defaultBranch = $branch;
                    $headResolved = true;
                }
            }
        }
        if (!$headResolved) {
            $headProcess = GitCommand::run($realPath, ['symbolic-ref', '--short', 'HEAD']);
            if ($headProcess->isSuccessful()) {
                $head = trim($headProcess->getOutput());
                if ('' !== $head) {
                    $defaultBranch = $head;
                }
            } else {
                $headProcess = GitCommand::run($realPath, ['rev-parse', '--abbrev-ref', 'HEAD']);
                if ($headProcess->isSuccessful()) {
                    $head = trim($headProcess->getOutput());
                    if ('' !== $head && 'HEAD' !== $head) {
                        $defaultBranch = $head;
                    }
                }
            }
        }

        // Latest commit time / updatedAt
        $updatedAt = null;
        $timeProcess = GitCommand::run($realPath, ['log', '-1', '--format=%ct', 'HEAD']);
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
        $pnameProcess = GitCommand::run($realPath, ['config', 'codes.project-name']);
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
        $process = $this->runOrStartTree($repo, $ref, $subpath, false);

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf('Failed to get tree for ref "%s": %s', $ref, $process->getErrorOutput()));
        }

        return $this->parseTreeOutput($process->getOutput());
    }

    /**
     * Starts a non-blocking `git ls-tree` process.
     */
    public function startTreeProcess(GitRepository $repo, string $ref = 'HEAD', string $subpath = ''): Process
    {
        return $this->runOrStartTree($repo, $ref, $subpath, true);
    }

    private function runOrStartTree(GitRepository $repo, string $ref, string $subpath, bool $async): Process
    {
        $args = ['ls-tree', '-l', '-z', new Revision($ref)];
        if ('' !== $subpath) {
            $args[] = '--';
            $args[] = rtrim($subpath, '/').'/';
        }

        return $async ? GitCommand::start($repo->path, $args) : GitCommand::run($repo->path, $args);
    }

    /**
     * Get all recursive file entries using `git ls-tree -r -l -z {$ref}`.
     *
     * @return list<TreeEntry>
     */
    public function getRecursiveTree(GitRepository $repo, string $ref = 'HEAD'): array
    {
        $process = GitCommand::run($repo->path, ['ls-tree', '-r', '-l', '-z', new Revision($ref)]);

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf('Failed to get recursive tree for ref "%s": %s', $ref, $process->getErrorOutput()));
        }

        return $this->parseTreeOutput($process->getOutput());
    }

    /**
     * Starts a non-blocking `git ls-tree -r -l -z` process for recursive file listing.
     */
    public function startRecursiveTreeProcess(GitRepository $repo, string $ref = 'HEAD'): Process
    {
        return GitCommand::start($repo->path, ['ls-tree', '-r', '-l', '-z', new Revision($ref)]);
    }

    /**
     * @return list<TreeEntry>
     */
    public function parseTreeOutput(string $output): array
    {
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
        $process = GitCommand::run($repo->path, ['cat-file', '-p', new Revision($target)]);

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf('Failed to get blob "%s": %s', $target, $process->getErrorOutput()));
        }

        return $process->getOutput();
    }

    /**
     * Starts a non-blocking `git cat-file -p` process.
     */
    public function startBlobProcess(GitRepository $repo, string $ref, string $path): Process
    {
        $target = sprintf('%s:%s', $ref, ltrim($path, '/'));

        return GitCommand::start($repo->path, ['cat-file', '-p', new Revision($target)]);
    }

    /**
     * Resolves the git object type (`blob`, `tree`, `commit`, `tag`) of a
     * `<rev>` or `<rev>:<path>` spec via `git cat-file -t`. Returns null when
     * the spec does not resolve (missing ref/path), so callers can answer 404.
     */
    public function objectType(GitRepository $repo, string $spec): ?string
    {
        $process = GitCommand::run($repo->path, ['cat-file', '-t', new Revision($spec)]);
        if (!$process->isSuccessful()) {
            return null;
        }
        $type = trim($process->getOutput());

        return '' !== $type ? $type : null;
    }

    /**
     * Returns the raw blob bytes at `<ref>:<path>`, or null when the ref/path does
     * not resolve to a blob (missing ref, missing path, or a tree). Distinguishes a
     * missing blob (null -> 404) from a successful read of empty content (""), so
     * the raw HTTP/SSH adapters never materialize a checkout.
     */
    public function catBlob(GitRepository $repo, string $ref, string $path): ?string
    {
        $spec = sprintf('%s:%s', $ref, ltrim($path, '/'));
        if ('blob' !== $this->objectType($repo, $spec)) {
            return null;
        }

        $process = GitCommand::run($repo->path, ['cat-file', '-p', new Revision($spec)]);

        return $process->isSuccessful() ? $process->getOutput() : null;
    }

    /**
     * Runs `git grep -n -I -e <pattern> <ref>` inside the bare repository. The
     * pattern follows git's native (basic) regex and is passed after `-e`, so a
     * leading "-" is never parsed as an option. Returns matching lines (empty on
     * "no match", which git signals with exit code 1), or null when the ref does
     * not resolve so the caller can emit an explicit error.
     *
     * @return list<string>|null
     */
    public function grep(GitRepository $repo, string $pattern, string $ref = 'HEAD'): ?array
    {
        $process = GitCommand::run($repo->path, ['grep', '-n', '-I', '-e', $pattern, new Revision($ref)]);
        $exit = $process->getExitCode();
        if (1 === $exit) {
            return [];
        }
        if (0 !== $exit) {
            return null;
        }

        $output = rtrim($process->getOutput(), "\n");
        if ('' === $output) {
            return [];
        }

        return explode("\n", $output);
    }

    /**
     * Total on-disk object size in bytes (loose objects + packs), computed on
     * demand from `git count-objects -v` (its `size` and `size-pack` fields are
     * reported in KiB). Returns null when the command fails or the output is
     * unparseable, so callers can show an "unavailable" state.
     */
    public function diskUsageBytes(GitRepository $repo): ?int
    {
        $process = GitCommand::run($repo->path, ['count-objects', '-v']);
        if (!$process->isSuccessful()) {
            return null;
        }

        $kib = 0;
        $matched = false;
        foreach (explode("\n", $process->getOutput()) as $line) {
            if (preg_match('/^(size|size-pack):\s*(\d+)$/', trim($line), $m)) {
                $kib += (int) $m[2];
                $matched = true;
            }
        }

        return $matched ? $kib * 1024 : null;
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

        $entry = $this->findReadmeEntry($entries);
        if (null === $entry) {
            return null;
        }

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

    /**
     * @param iterable<TreeEntry> $entries
     */
    public function findReadmeEntry(iterable $entries): ?TreeEntry
    {
        $candidates = ['readme.md', 'readme', 'readme.markdown', 'readme.txt'];
        $filesByName = [];
        foreach ($entries as $entry) {
            if ($entry->isFile()) {
                $filesByName[strtolower($entry->name)] = $entry;
            }
        }

        foreach ($candidates as $candidate) {
            if (isset($filesByName[$candidate])) {
                return $filesByName[$candidate];
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
        $process = $this->runOrStartCommits($repo, $ref, $limit, $offset, false);

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf('Failed to get commits for ref "%s": %s', $ref, $process->getErrorOutput()));
        }

        return $this->parseCommitsOutput($process->getOutput());
    }

    /**
     * Starts a non-blocking `git log` process.
     */
    public function startCommitsProcess(GitRepository $repo, string $ref = 'HEAD', int $limit = 50, int $offset = 0): Process
    {
        return $this->runOrStartCommits($repo, $ref, $limit, $offset, true);
    }

    private function runOrStartCommits(GitRepository $repo, string $ref, int $limit, int $offset, bool $async): Process
    {
        $format = '%H%x00%h%x00%an%x00%ae%x00%at%x00%s%x00%b';
        $args = [
            'log',
            '-z',
            '--format=format:'.$format,
            '-n',
            (string) $limit,
            '--skip='.(string) $offset,
            new Revision($ref),
        ];

        return $async ? GitCommand::start($repo->path, $args) : GitCommand::run($repo->path, $args);
    }

    /**
     * @return list<Commit>
     */
    public function parseCommitsOutput(string $output): array
    {
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
            $args = ['diff', new Revision(sprintf('%s..%s', $refA, $refB))];
        } else {
            $testProcess = GitCommand::run($repo->path, ['show', '--patch', '--format=', new Revision($refA)]);
            if ($testProcess->isSuccessful() && '' !== $testProcess->getOutput()) {
                return $this->parseDiffOutput($testProcess->getOutput());
            }

            // Fallback to git diff $refA
            $args = ['diff', new Revision($refA)];
        }

        $process = GitCommand::run($repo->path, $args);

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf('Failed to get diff: %s', $process->getErrorOutput()));
        }

        return $this->parseDiffOutput($process->getOutput());
    }

    /**
     * Starts a non-blocking `git show --patch` process for a single commit diff.
     */
    public function startCommitDiffProcess(GitRepository $repo, string $sha): Process
    {
        return GitCommand::start($repo->path, ['show', '--patch', '--format=', new Revision($sha)]);
    }

    /**
     * Starts a non-blocking `git for-each-ref` process returning all branches and tags in a single call.
     */
    public function startRefsProcess(GitRepository $repo): Process
    {
        return GitCommand::start($repo->path, [
            'for-each-ref',
            '--format=%(refname)%09%(objectname)%09%(committerdate:unix)%09%(creatordate:unix)',
            'refs/heads',
            'refs/tags',
        ]);
    }

    /**
     * Parses the output of {@see startRefsProcess()} into branches, tags, and per-ref commit metadata.
     *
     * @return array{branches: list<string>, tags: list<string>, meta: array<string, array{hash: string, time: int}>}
     */
    public function parseRefsProcessOutput(string $output): array
    {
        $branches = [];
        $tags = [];
        $meta = [];

        foreach (explode("\n", trim($output)) as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            $parts = explode("\t", $line);
            $refname = $parts[0];
            $hash = $parts[1] ?? '';
            $committerTs = (int) ($parts[2] ?? 0);
            $creatorTs = (int) ($parts[3] ?? 0);
            $time = $committerTs > 0 ? $committerTs : $creatorTs;

            if (str_starts_with($refname, 'refs/heads/')) {
                $short = substr($refname, \strlen('refs/heads/'));
                if ('' !== $short) {
                    $branches[] = $short;
                    if ('' !== $hash) {
                        $meta[$short] = ['hash' => $hash, 'time' => $time];
                    }
                }
            } elseif (str_starts_with($refname, 'refs/tags/')) {
                $short = substr($refname, \strlen('refs/tags/'));
                if ('' !== $short) {
                    $tags[] = $short;
                    if ('' !== $hash) {
                        $meta[$short] = ['hash' => $hash, 'time' => $time];
                    }
                }
            }
        }

        return ['branches' => $branches, 'tags' => $tags, 'meta' => $meta];
    }

    /**
     * Output of `git show [--format=] <revision>`, or null when the revision does not resolve.
     */
    public function show(GitRepository $repo, string $revision, bool $patchOnly = false): ?string
    {
        $args = ['show'];
        if ($patchOnly) {
            $args[] = '--format=';
        }
        $args[] = new Revision($revision);

        $process = GitCommand::run($repo->path, $args);

        return $process->isSuccessful() ? $process->getOutput() : null;
    }

    /**
     * Get repository branches, current branch, and tags.
     */
    public function getRefs(GitRepository $repo): Refs
    {
        // Branches
        $branchProcess = GitCommand::run($repo->path, ['branch', '--list', '--format=%(refname:short)']);
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
        $currProcess = GitCommand::run($repo->path, ['rev-parse', '--abbrev-ref', 'HEAD']);
        if ($currProcess->isSuccessful()) {
            $curr = trim($currProcess->getOutput());
            if ('' !== $curr && 'HEAD' !== $curr) {
                $currentBranch = $curr;
            }
        }

        // Tags
        $tagProcess = GitCommand::run($repo->path, ['tag', '--list']);
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
        // No `--` here: blame rejects `--end-of-options <rev> -- <path>`; after the marker the path is positional anyway.
        $process = GitCommand::run($repo->path, ['blame', '--date=short', new Revision($ref), ltrim($path, '/')]);
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
    public function parseDiffOutput(string $rawDiff): Diff
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

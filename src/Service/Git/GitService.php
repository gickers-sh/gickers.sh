<?php

declare(strict_types=1);

namespace App\Service\Git;

use App\Model\CommitModel;
use App\Model\DiffFileModel;
use App\Model\DiffModel;
use App\Model\GitRepositoryModel;
use App\Model\RefsModel;
use App\Model\TreeEntryModel;
use RuntimeException;
use Symfony\Component\Process\Process;

class GitService
{
    /**
     * Open a git repository from the given path.
     * Validates directory, detects bare or non-bare repository,
     * reads description from .git/description or description file,
     * and resolves HEAD branch.
     */
    public function openRepository(string $path): GitRepositoryModel
    {
        $realPath = realpath($path);
        if ($realPath === false || !is_dir($realPath)) {
            throw new RuntimeException(sprintf('Path "%s" does not exist or is not a directory.', $path));
        }

        $isBare = false;
        $gitDir = $realPath . '/.git';

        if (is_dir($gitDir)) {
            $isBare = false;
        } elseif (is_file($realPath . '/HEAD') && (is_dir($realPath . '/objects') || is_dir($realPath . '/refs'))) {
            $isBare = true;
            $gitDir = $realPath;
        } else {
            // Verify via git command if inside a working tree or bare repo
            $process = new Process(['git', 'rev-parse', '--is-bare-repository'], $realPath);
            $process->run();
            if (!$process->isSuccessful()) {
                throw new RuntimeException(sprintf('Path "%s" is not a valid git repository.', $path));
            }
            $isBare = trim($process->getOutput()) === 'true';
            $gitDir = $isBare ? $realPath : $realPath . '/.git';
        }

        $name = basename($realPath);
        if (str_ends_with($name, '.git')) {
            $name = substr($name, 0, -4);
        }

        // Description
        $description = '';
        $descFile = $gitDir . '/description';
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
            if ($head !== '') {
                $defaultBranch = $head;
            }
        } else {
            // Fallback: rev-parse abbrev-ref HEAD
            $headProcess = new Process(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], $realPath);
            $headProcess->run();
            if ($headProcess->isSuccessful()) {
                $head = trim($headProcess->getOutput());
                if ($head !== '' && $head !== 'HEAD') {
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
            if ($ct !== '' && ctype_digit($ct)) {
                $updatedAt = (new \DateTimeImmutable())->setTimestamp((int) $ct);
            }
        }
        if ($updatedAt === null && is_dir($realPath)) {
            $mtime = @filemtime($realPath);
            if ($mtime !== false) {
                $updatedAt = (new \DateTimeImmutable())->setTimestamp($mtime);
            }
        }

        // Project name (read from git config if present)
        $projectName = null;
        $pnameProcess = new Process(['git', 'config', 'codes.project-name'], $realPath);
        $pnameProcess->run();
        if ($pnameProcess->isSuccessful()) {
            $pname = trim($pnameProcess->getOutput());
            if ($pname !== '') {
                $projectName = $pname;
            }
        }

        return new GitRepositoryModel(
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
     * Entry format: `<mode> <type> <hash> <size>\t<path>\0`
     *
     * @return TreeEntryModel[]
     */
    public function getTree(GitRepositoryModel $repo, string $ref = 'HEAD', string $subpath = ''): array
    {
        $args = ['git', 'ls-tree', '-l', '-z', $ref];
        if ($subpath !== '') {
            $args[] = '--';
            $args[] = $subpath;
        }

        $process = new Process($args, $repo->path);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(sprintf('Failed to get tree for ref "%s": %s', $ref, $process->getErrorOutput()));
        }

        $output = $process->getOutput();
        if ($output === '') {
            return [];
        }

        $rawEntries = explode("\0", $output);
        $entries = [];

        foreach ($rawEntries as $rawEntry) {
            if ($rawEntry === '') {
                continue;
            }

            // Tab separates metadata from relative path
            $tabPos = strpos($rawEntry, "\t");
            if ($tabPos === false) {
                continue;
            }

            $meta = substr($rawEntry, 0, $tabPos);
            $entryPath = substr($rawEntry, $tabPos + 1);

            // <mode> <type> <hash> <size>
            // Note: size can be multiple spaces padded or "-" for trees
            $parts = preg_split('/\s+/', trim($meta));
            if ($parts === false || count($parts) < 4) {
                continue;
            }

            $mode = $parts[0];
            $type = $parts[1];
            $hash = $parts[2];
            $sizeRaw = $parts[3];
            $size = ($sizeRaw === '-' || !is_numeric($sizeRaw)) ? null : (int) $sizeRaw;

            $name = basename($entryPath);

            $entries[] = new TreeEntryModel(
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
    public function getBlob(GitRepositoryModel $repo, string $ref, string $path): string
    {
        $target = sprintf('%s:%s', $ref, ltrim($path, '/'));
        $process = new Process(['git', 'cat-file', '-p', $target], $repo->path);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(sprintf('Failed to get blob "%s": %s', $target, $process->getErrorOutput()));
        }

        return $process->getOutput();
    }

    /**
     * Get commits using `git log -z --format=format:"%H%x00%h%x00%an%x00%ae%x00%at%x00%s%x00%b" -n {$limit} --skip={$offset} {$ref}`.
     *
     * @return CommitModel[]
     */
    public function getCommits(GitRepositoryModel $repo, string $ref = 'HEAD', int $limit = 50, int $offset = 0): array
    {
        $format = '%H%x00%h%x00%an%x00%ae%x00%at%x00%s%x00%b';
        $args = [
            'git',
            'log',
            '-z',
            '--format=format:' . $format,
            '-n',
            (string) $limit,
            '--skip=' . (string) $offset,
            $ref,
        ];

        $process = new Process($args, $repo->path);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(sprintf('Failed to get commits for ref "%s": %s', $ref, $process->getErrorOutput()));
        }

        $output = $process->getOutput();
        if ($output === '') {
            return [];
        }

        // Output has records separated by null bytes.
        // Each commit produces 7 fields separated by null bytes:
        // 0: Hash (%H)
        // 1: Short Hash (%h)
        // 2: Author Name (%an)
        // 3: Author Email (%ae)
        // 4: Timestamp (%at)
        // 5: Subject (%s)
        // 6: Body (%b)
        $parts = explode("\0", $output);
        $commits = [];
        $totalParts = count($parts);

        for ($i = 0; $i + 6 < $totalParts; $i += 7) {
            $hash = $parts[$i];
            if ($hash === '') {
                break;
            }
            $shortHash = $parts[$i + 1] ?? substr($hash, 0, 7);
            $authorName = $parts[$i + 2] ?? '';
            $authorEmail = $parts[$i + 3] ?? '';
            $timestamp = (int) ($parts[$i + 4] ?? 0);
            $subject = $parts[$i + 5] ?? '';
            $body = trim($parts[$i + 6] ?? '');

            $commits[] = new CommitModel(
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
     *   Otherwise: `git diff {$refA}`
     */
    public function getDiff(GitRepositoryModel $repo, string $refA, ?string $refB = null): DiffModel
    {
        if ($refB !== null && $refB !== '') {
            $args = ['git', 'diff', sprintf('%s..%s', $refA, $refB)];
        } else {
            // Determine if refA is a single commit (e.g. check if parent exists or use git show)
            // If refA is a commit hash/tag, git show gives the diff introduced by that commit
            // Let's test if refA resolves to a commit and user passed a single revision
            $args = ['git', 'show', '--patch', '--format=', $refA];
            $testProcess = new Process($args, $repo->path);
            $testProcess->run();
            if ($testProcess->isSuccessful() && $testProcess->getOutput() !== '') {
                return $this->parseDiffOutput($testProcess->getOutput());
            }

            // Fallback to git diff $refA
            $args = ['git', 'diff', $refA];
        }

        $process = new Process($args, $repo->path);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(sprintf('Failed to get diff: %s', $process->getErrorOutput()));
        }

        return $this->parseDiffOutput($process->getOutput());
    }

    /**
     * Get repository branches, current branch, and tags.
     */
    public function getRefs(GitRepositoryModel $repo): RefsModel
    {
        // Branches
        $branchProcess = new Process(['git', 'branch', '--list', '--format=%(refname:short)'], $repo->path);
        $branchProcess->run();
        $branches = [];
        if ($branchProcess->isSuccessful()) {
            $raw = trim($branchProcess->getOutput());
            if ($raw !== '') {
                $branches = array_filter(array_map('trim', explode("\n", $raw)), fn($b) => $b !== '');
                $branches = array_values($branches);
            }
        }

        // Current branch
        $currentBranch = $repo->defaultBranch;
        $currProcess = new Process(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], $repo->path);
        $currProcess->run();
        if ($currProcess->isSuccessful()) {
            $curr = trim($currProcess->getOutput());
            if ($curr !== '' && $curr !== 'HEAD') {
                $currentBranch = $curr;
            }
        }

        // Tags
        $tagProcess = new Process(['git', 'tag', '--list'], $repo->path);
        $tagProcess->run();
        $tags = [];
        if ($tagProcess->isSuccessful()) {
            $raw = trim($tagProcess->getOutput());
            if ($raw !== '') {
                $tags = array_filter(array_map('trim', explode("\n", $raw)), fn($t) => $t !== '');
                $tags = array_values($tags);
            }
        }

        return new RefsModel(
            branches: $branches,
            currentBranch: $currentBranch,
            tags: $tags,
        );
    }

    /**
     * Commit a file to the repository.
     * Ensures target directory exists, writes file, stages it, and commits.
     */
    public function commitFile(
        GitRepositoryModel $repo,
        string $branch,
        string $path,
        string $content,
        string $message,
        string $authorName,
        string $authorEmail,
    ): void {
        // If repo is bare, we can use git hash-object, git mktree/update-index, git commit-tree
        if ($repo->isBare) {
            $this->commitFileBare($repo, $branch, $path, $content, $message, $authorName, $authorEmail);
            return;
        }

        $fullPath = $repo->path . '/' . ltrim($path, '/');
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException(sprintf('Failed to create directory "%s".', $dir));
            }
        }

        if (file_put_contents($fullPath, $content) === false) {
            throw new RuntimeException(sprintf('Failed to write file "%s".', $fullPath));
        }

        // Git add
        $addProcess = new Process(['git', 'add', $path], $repo->path);
        $addProcess->run();
        if (!$addProcess->isSuccessful()) {
            throw new RuntimeException(sprintf('Failed to git add "%s": %s', $path, $addProcess->getErrorOutput()));
        }

        // Git commit with custom author
        $authorString = sprintf('%s <%s>', $authorName, $authorEmail);
        $commitProcess = new Process(
            ['git', 'commit', '-m', $message, '--author=' . $authorString],
            $repo->path,
            [
                'GIT_AUTHOR_NAME' => $authorName,
                'GIT_AUTHOR_EMAIL' => $authorEmail,
                'GIT_COMMITTER_NAME' => $authorName,
                'GIT_COMMITTER_EMAIL' => $authorEmail,
            ]
        );
        $commitProcess->run();

        if (!$commitProcess->isSuccessful()) {
            throw new RuntimeException(sprintf('Failed to commit file "%s": %s', $path, $commitProcess->getErrorOutput()));
        }
    }

    /**
     * Parse raw diff output into a structured DiffModel.
     */
    private function parseDiffOutput(string $rawDiff): DiffModel
    {
        if (trim($rawDiff) === '') {
            return new DiffModel(
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
        $hunks = preg_split('/(?=diff --git )/', $rawDiff, -1, PREG_SPLIT_NO_EMPTY);
        if ($hunks === false) {
            return new DiffModel([], 0, 0, $rawDiff);
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
                    $fileAdditions++;
                } elseif (str_starts_with($line, '-') && !str_starts_with($line, '---')) {
                    $fileDeletions++;
                }
            }

            if ($oldPath === '' && $newPath === '') {
                $oldPath = 'unknown';
                $newPath = 'unknown';
            }

            $totalAdditions += $fileAdditions;
            $totalDeletions += $fileDeletions;

            $files[] = new DiffFileModel(
                oldPath: $oldPath,
                newPath: $newPath,
                status: $status,
                patch: $hunk,
                additions: $fileAdditions,
                deletions: $fileDeletions,
            );
        }

        return new DiffModel(
            files: $files,
            totalAdditions: $totalAdditions,
            totalDeletions: $totalDeletions,
            rawDiff: $rawDiff,
        );
    }

    /**
     * Commit a file into a bare repository using git plumbing commands.
     */
    private function commitFileBare(
        GitRepositoryModel $repo,
        string $branch,
        string $path,
        string $content,
        string $message,
        string $authorName,
        string $authorEmail,
    ): void {
        // 1. Write blob: git hash-object -w --stdin
        $hashProcess = new Process(['git', 'hash-object', '-w', '--stdin'], $repo->path);
        $hashProcess->setInput($content);
        $hashProcess->run();
        if (!$hashProcess->isSuccessful()) {
            throw new RuntimeException('Failed to hash-object: ' . $hashProcess->getErrorOutput());
        }
        $blobHash = trim($hashProcess->getOutput());

        // 2. Read parent tree/commit if branch exists
        $parentCommit = null;
        $revProcess = new Process(['git', 'rev-parse', '--verify', $branch], $repo->path);
        $revProcess->run();
        if ($revProcess->isSuccessful()) {
            $parentCommit = trim($revProcess->getOutput());
        }

        // 3. Temporary index file for building tree
        $tempIndex = tempnam(sys_get_temp_dir(), 'git_idx_');
        if ($tempIndex === false) {
            throw new RuntimeException('Failed to create temp index file.');
        }

        $env = [
            'GIT_INDEX_FILE' => $tempIndex,
            'GIT_AUTHOR_NAME' => $authorName,
            'GIT_AUTHOR_EMAIL' => $authorEmail,
            'GIT_COMMITTER_NAME' => $authorName,
            'GIT_COMMITTER_EMAIL' => $authorEmail,
        ];

        try {
            // Read existing tree into temp index if branch exists
            if ($parentCommit !== null) {
                $readTree = new Process(['git', 'read-tree', $branch], $repo->path, $env);
                $readTree->run();
                if (!$readTree->isSuccessful()) {
                    throw new RuntimeException('Failed to read-tree: ' . $readTree->getErrorOutput());
                }
            }

            // Update index with new blob
            $updateIndex = new Process(
                ['git', 'update-index', '--add', '--cacheinfo', '100644', $blobHash, ltrim($path, '/')],
                $repo->path,
                $env
            );
            $updateIndex->run();
            if (!$updateIndex->isSuccessful()) {
                throw new RuntimeException('Failed to update-index: ' . $updateIndex->getErrorOutput());
            }

            // Write tree
            $writeTree = new Process(['git', 'write-tree'], $repo->path, $env);
            $writeTree->run();
            if (!$writeTree->isSuccessful()) {
                throw new RuntimeException('Failed to write-tree: ' . $writeTree->getErrorOutput());
            }
            $treeHash = trim($writeTree->getOutput());

            // Commit tree
            $commitArgs = ['git', 'commit-tree', $treeHash, '-m', $message];
            if ($parentCommit !== null) {
                $commitArgs[] = '-p';
                $commitArgs[] = $parentCommit;
            }

            $commitTree = new Process($commitArgs, $repo->path, $env);
            $commitTree->run();
            if (!$commitTree->isSuccessful()) {
                throw new RuntimeException('Failed to commit-tree: ' . $commitTree->getErrorOutput());
            }
            $commitHash = trim($commitTree->getOutput());

            // Update branch ref
            $refName = str_starts_with($branch, 'refs/') ? $branch : 'refs/heads/' . $branch;
            $updateRef = new Process(['git', 'update-ref', $refName, $commitHash], $repo->path);
            $updateRef->run();
            if (!$updateRef->isSuccessful()) {
                throw new RuntimeException('Failed to update-ref: ' . $updateRef->getErrorOutput());
            }
        } finally {
            if (file_exists($tempIndex)) {
                unlink($tempIndex);
            }
        }
    }

    /**
     * Run git blame on a file and return formatted blame lines.
     *
     * @return string[]
     */
    public function getBlame(GitRepositoryModel $repo, string $ref, string $path): array
    {
        $process = new Process(['git', 'blame', '--date=short', $ref, '--', ltrim($path, '/')], $repo->path);
        $process->run();
        if (!$process->isSuccessful()) {
            return [];
        }
        $output = trim($process->getOutput());
        if ($output === '') {
            return [];
        }
        return explode("\n", $output);
    }
}

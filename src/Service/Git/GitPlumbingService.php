<?php

declare(strict_types=1);

namespace App\Service\Git;

use App\Model\GitRepositoryModel;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

final class GitPlumbingService
{
 /**
  * Validates and normalizes a relative file path for repository writes.
  * Rejects empty paths, absolute paths, traversal (..), trailing slashes,
  * and any .git segment.
  *
  * @throws InvalidArgumentException
  */
 public function validateRelativePath(string $path): string
 {
  $trimmed = trim($path);
  if ($trimmed === '' || $trimmed === '.') {
   throw new InvalidArgumentException('Path cannot be empty.');
  }

  if (str_ends_with($path, '/') || str_ends_with($path, '\\')) {
   throw new InvalidArgumentException('Path cannot have a trailing slash.');
  }

  // Normalize backslashes to forward slashes
  $normalized = str_replace('\\', '/', $trimmed);

  if (str_starts_with($normalized, '/') || preg_match('/^[a-zA-Z]:\//', $normalized)) {
   throw new InvalidArgumentException('Path must be relative, cannot be absolute.');
  }

  $segments = explode('/', $normalized);
  $cleanSegments = [];
  foreach ($segments as $segment) {
   if ($segment === '' || $segment === '.') {
    continue;
   }
   if ($segment === '..') {
    throw new InvalidArgumentException("Path traversal ('..') is forbidden.");
   }
   if (strtolower($segment) === '.git') {
    throw new InvalidArgumentException("Path cannot contain '.git' segment.");
   }
   $cleanSegments[] = $segment;
  }

  if (empty($cleanSegments)) {
   throw new InvalidArgumentException('Path cannot be empty.');
  }

  return implode('/', $cleanSegments);
 }

 /**
  * Initializes a new bare repository.
  */
 public function initBareRepository(
  string $path,
  string $defaultBranch = 'main',
  string $description = ''
 ): GitRepositoryModel {
  if (!is_dir($path)) {
   if (!mkdir($path, 0755, true) && !is_dir($path)) {
    throw new RuntimeException(sprintf('Failed to create directory: %s', $path));
   }
  }

  $process = new Process(['git', 'init', '--bare', '--initial-branch=' . $defaultBranch, $path]);
  $process->run();
  if (!$process->isSuccessful()) {
   // Fallback for older git versions
   $fallback = new Process(['git', 'init', '--bare', $path]);
   $fallback->mustRun();
   $symRef = new Process(['git', 'symbolic-ref', 'HEAD', 'refs/heads/' . $defaultBranch], $path);
   $symRef->mustRun();
  }

  if ($description !== '') {
   file_put_contents($path . '/description', $description . "\n");
  }

  $realPath = realpath($path) ?: $path;
  return new GitRepositoryModel(
   name: basename($realPath),
   path: $realPath,
   isBare: true,
   description: $description,
   defaultBranch: $defaultBranch
  );
 }

 /**
  * Clones an existing repository as a bare repository.
  */
 public function forkBareRepository(
  string $sourcePath,
  string $targetPath,
  string $description = ''
 ): GitRepositoryModel {
  $process = new Process(['git', 'clone', '--bare', $sourcePath, $targetPath]);
  $process->mustRun();

  if ($description !== '') {
   file_put_contents($targetPath . '/description', $description . "\n");
  }

  $defaultBranch = 'main';
  $symRef = new Process(['git', 'symbolic-ref', '--short', 'HEAD'], $targetPath);
  $symRef->run();
  if ($symRef->isSuccessful()) {
   $defaultBranch = trim($symRef->getOutput());
  }

  $realPath = realpath($targetPath) ?: $targetPath;
  return new GitRepositoryModel(
   name: basename($realPath),
   path: $realPath,
   isBare: true,
   description: $description,
   defaultBranch: $defaultBranch
  );
 }

 /**
  * Writes or updates a file in the repository using low-level Git plumbing.
  * Works on both bare and non-bare repositories without worktree checkouts.
  *
  * @return string The newly created commit SHA
  */
 public function writeFile(
  GitRepositoryModel $repo,
  string $branch,
  string $filePath,
  string $content,
  string $message,
  string $authorName,
  string $authorEmail
 ): string {
  $cleanPath = $this->validateRelativePath($filePath);

  if (trim($message) === '') {
   $message = 'Update ' . basename($cleanPath);
  }

  // 1. Hash object and write blob into git object database
  $hashProcess = new Process(['git', 'hash-object', '-w', '--stdin'], $repo->path);
  $hashProcess->setInput($content);
  $hashProcess->run();
  if (!$hashProcess->isSuccessful()) {
   throw new RuntimeException('Failed to hash-object: ' . $hashProcess->getErrorOutput());
  }
  $blobHash = trim($hashProcess->getOutput());

  // 2. Resolve parent commit if branch exists
  $parentCommit = $this->resolveCommitSha($repo, $branch);

  // 3. Prepare temporary isolated index file
  $tempIndex = tempnam(sys_get_temp_dir(), 'git_plumb_idx_');
  if ($tempIndex === false) {
   throw new RuntimeException('Failed to create temporary index file.');
  }
  @unlink($tempIndex);

  $env = [
   'GIT_INDEX_FILE' => $tempIndex,
   'GIT_AUTHOR_NAME' => $authorName,
   'GIT_AUTHOR_EMAIL' => $authorEmail,
   'GIT_COMMITTER_NAME' => $authorName,
   'GIT_COMMITTER_EMAIL' => $authorEmail,
  ];

  try {
   // 4. Read tree of parent commit if exists
   $mode = '100644';
   if ($parentCommit !== null) {
    $readTree = new Process(['git', 'read-tree', $parentCommit], $repo->path, $env);
    $readTree->run();
    if (!$readTree->isSuccessful()) {
     throw new RuntimeException('Failed to read-tree: ' . $readTree->getErrorOutput());
    }

    // Preserve 100755 if previously executable
    $lsTree = new Process(['git', 'ls-tree', $parentCommit, '--', $cleanPath], $repo->path);
    $lsTree->run();
    if ($lsTree->isSuccessful() && str_starts_with(trim($lsTree->getOutput()), '100755')) {
     $mode = '100755';
    }
   }

   // 5. Update index with new blob
   $updateIndex = new Process(
    ['git', 'update-index', '--add', '--cacheinfo', sprintf('%s,%s,%s', $mode, $blobHash, $cleanPath)],
    $repo->path,
    $env
   );
   $updateIndex->run();
   if (!$updateIndex->isSuccessful()) {
    throw new RuntimeException('Failed to update-index: ' . $updateIndex->getErrorOutput());
   }

   // 6. Write tree
   $writeTree = new Process(['git', 'write-tree'], $repo->path, $env);
   $writeTree->run();
   if (!$writeTree->isSuccessful()) {
    throw new RuntimeException('Failed to write-tree: ' . $writeTree->getErrorOutput());
   }
   $treeHash = trim($writeTree->getOutput());

   // 7. Commit tree
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

   // 8. Update ref
   $refName = str_starts_with($branch, 'refs/') ? $branch : 'refs/heads/' . $branch;
   $updateRefArgs = ['git', 'update-ref', $refName, $commitHash];
   if ($parentCommit !== null) {
    $updateRefArgs[] = $parentCommit;
   }
   $updateRef = new Process($updateRefArgs, $repo->path);
   $updateRef->run();
   if (!$updateRef->isSuccessful()) {
    throw new RuntimeException('Failed to update-ref: ' . $updateRef->getErrorOutput());
   }

   // 9. If non-bare, keep working tree in sync
   if (!$repo->isBare) {
    $dest = $repo->path . '/' . $cleanPath;
    $destDir = dirname($dest);
    if (!is_dir($destDir)) {
     mkdir($destDir, 0755, true);
    }
    file_put_contents($dest, $content);
    if ($mode === '100755') {
     chmod($dest, 0755);
    }
   }

   return $commitHash;
  } finally {
   if (file_exists($tempIndex)) {
    unlink($tempIndex);
   }
  }
 }

 /**
  * Deletes a file and commits the removal using low-level Git plumbing.
  *
  * @return string The newly created commit SHA
  */
 public function deleteFile(
  GitRepositoryModel $repo,
  string $branch,
  string $filePath,
  string $message,
  string $authorName,
  string $authorEmail
 ): string {
  $cleanPath = $this->validateRelativePath($filePath);

  $parentCommit = $this->resolveCommitSha($repo, $branch);
  if ($parentCommit === null) {
   throw new RuntimeException(sprintf('Cannot delete file from non-existent branch "%s".', $branch));
  }

  // Verify file exists on target ref
  $lsTree = new Process(['git', 'ls-tree', $parentCommit, '--', $cleanPath], $repo->path);
  $lsTree->run();
  if (!$lsTree->isSuccessful() || trim($lsTree->getOutput()) === '') {
   throw new InvalidArgumentException(sprintf('File "%s" does not exist on ref "%s".', $cleanPath, $branch));
  }

  if (trim($message) === '') {
   $message = 'Delete ' . basename($cleanPath);
  }

  $tempIndex = tempnam(sys_get_temp_dir(), 'git_plumb_idx_');
  if ($tempIndex === false) {
   throw new RuntimeException('Failed to create temporary index file.');
  }
  @unlink($tempIndex);

  $env = [
   'GIT_INDEX_FILE' => $tempIndex,
   'GIT_AUTHOR_NAME' => $authorName,
   'GIT_AUTHOR_EMAIL' => $authorEmail,
   'GIT_COMMITTER_NAME' => $authorName,
   'GIT_COMMITTER_EMAIL' => $authorEmail,
  ];

  try {
   // Read parent tree into temp index
   $readTree = new Process(['git', 'read-tree', $parentCommit], $repo->path, $env);
   $readTree->run();
   if (!$readTree->isSuccessful()) {
    throw new RuntimeException('Failed to read-tree: ' . $readTree->getErrorOutput());
   }

   // Remove file from index via --index-info (works cleanly in bare repos without worktree)
   $updateIndex = new Process(['git', 'update-index', '--index-info'], $repo->path, $env);
   $updateIndex->setInput(sprintf("0 0000000000000000000000000000000000000000\t%s\n", $cleanPath));
   $updateIndex->run();
   if (!$updateIndex->isSuccessful()) {
    throw new RuntimeException('Failed to update-index --index-info: ' . $updateIndex->getErrorOutput());
   }

   // Write tree
   $writeTree = new Process(['git', 'write-tree'], $repo->path, $env);
   $writeTree->run();
   if (!$writeTree->isSuccessful()) {
    throw new RuntimeException('Failed to write-tree: ' . $writeTree->getErrorOutput());
   }
   $treeHash = trim($writeTree->getOutput());

   // Commit tree
   $commitTree = new Process(
    ['git', 'commit-tree', $treeHash, '-p', $parentCommit, '-m', $message],
    $repo->path,
    $env
   );
   $commitTree->run();
   if (!$commitTree->isSuccessful()) {
    throw new RuntimeException('Failed to commit-tree: ' . $commitTree->getErrorOutput());
   }
   $commitHash = trim($commitTree->getOutput());

   // Update ref
   $refName = str_starts_with($branch, 'refs/') ? $branch : 'refs/heads/' . $branch;
   $updateRef = new Process(['git', 'update-ref', $refName, $commitHash, $parentCommit], $repo->path);
   $updateRef->run();
   if (!$updateRef->isSuccessful()) {
    throw new RuntimeException('Failed to update-ref: ' . $updateRef->getErrorOutput());
   }

   // If non-bare, remove working tree file
   if (!$repo->isBare) {
    $dest = $repo->path . '/' . $cleanPath;
    if (file_exists($dest)) {
     unlink($dest);
    }
   }

   return $commitHash;
  } finally {
   if (file_exists($tempIndex)) {
    unlink($tempIndex);
   }
  }
 }

 /**
  * Creates a new branch pointing to startPoint (default HEAD).
  */
 public function createBranch(GitRepositoryModel $repo, string $branchName, string $startPoint = 'HEAD'): void
 {
  $sha = $this->resolveCommitSha($repo, $startPoint);
  if ($sha === null) {
   throw new RuntimeException(sprintf('Cannot resolve start point "%s".', $startPoint));
  }

  $refName = 'refs/heads/' . $branchName;
  $check = new Process(['git', 'show-ref', '--verify', $refName], $repo->path);
  $check->run();
  if ($check->isSuccessful()) {
   throw new RuntimeException(sprintf('Branch "%s" already exists.', $branchName));
  }

  $create = new Process(['git', 'update-ref', $refName, $sha], $repo->path);
  $create->mustRun();
 }

 /**
  * Deletes a branch.
  */
 public function deleteBranch(GitRepositoryModel $repo, string $branchName): void
 {
  $refName = str_starts_with($branchName, 'refs/heads/') ? $branchName : 'refs/heads/' . $branchName;
  $process = new Process(['git', 'update-ref', '-d', $refName], $repo->path);
  $process->run();
  if (!$process->isSuccessful()) {
   throw new RuntimeException(sprintf('Failed to delete branch "%s": %s', $branchName, $process->getErrorOutput()));
  }
 }

 /**
  * Creates a lightweight or annotated tag.
  */
 public function createTag(
  GitRepositoryModel $repo,
  string $tagName,
  string $target = 'HEAD',
  ?string $message = null,
  string $authorName = 'codes.sh',
  string $authorEmail = 'noreply@codes.sh'
 ): void {
  if ($message !== null) {
   $env = [
    'GIT_TAGGER_NAME' => $authorName,
    'GIT_TAGGER_EMAIL' => $authorEmail,
   ];
   $process = new Process(['git', 'tag', '-a', $tagName, '-m', $message, $target], $repo->path, $env);
  } else {
   $process = new Process(['git', 'tag', $tagName, $target], $repo->path);
  }

  $process->mustRun();
 }

 /**
  * Deletes a tag.
  */
 public function deleteTag(GitRepositoryModel $repo, string $tagName): void
 {
  $process = new Process(['git', 'tag', '-d', $tagName], $repo->path);
  $process->run();
  if (!$process->isSuccessful()) {
   throw new RuntimeException(sprintf('Failed to delete tag "%s": %s', $tagName, $process->getErrorOutput()));
  }
 }

 /**
  * Tests whether headRef can be cleanly merged into baseBranch without conflicts.
  * Uses in-memory `git merge-tree --write-tree`.
  */
 public function canMerge(GitRepositoryModel $repo, string $baseBranch, string $headRef): bool
 {
  $baseSHA = $this->resolveCommitSha($repo, $baseBranch);
  $headSHA = $this->resolveCommitSha($repo, $headRef);

  if ($baseSHA === null || $headSHA === null) {
   return false;
  }

  // Check if head is already merged into base
  $ancestorProc = new Process(['git', 'merge-base', '--is-ancestor', $headSHA, $baseSHA], $repo->path);
  $ancestorProc->run();
  if ($ancestorProc->isSuccessful()) {
   return false;
  }

  $mergeTree = new Process(['git', 'merge-tree', '--write-tree', $baseSHA, $headSHA], $repo->path);
  $mergeTree->run();

  return $mergeTree->isSuccessful();
 }

 /**
  * Merges headRef into baseBranch using the given strategy ('merge', 'squash', 'fast-forward').
  *
  * @return string The resulting commit SHA
  */
 public function mergePullRequest(
  GitRepositoryModel $repo,
  string $baseBranch,
  string $headRef,
  string $strategy,
  string $message,
  string $authorName,
  string $authorEmail
 ): string {
  $allowedStrategies = ['merge', 'squash', 'fast-forward'];
  if (!in_array($strategy, $allowedStrategies, true)) {
   throw new InvalidArgumentException(sprintf('Unknown merge strategy "%s". Allowed: %s', $strategy, implode(', ', $allowedStrategies)));
  }

  $baseSHA = $this->resolveCommitSha($repo, $baseBranch);
  if ($baseSHA === null) {
   throw new RuntimeException(sprintf('Cannot resolve base ref "%s".', $baseBranch));
  }

  $headSHA = $this->resolveCommitSha($repo, $headRef);
  if ($headSHA === null) {
   throw new RuntimeException(sprintf('Cannot resolve head ref "%s".', $headRef));
  }

  // Check if already merged
  $ancestorCheck = new Process(['git', 'merge-base', '--is-ancestor', $headSHA, $baseSHA], $repo->path);
  $ancestorCheck->run();
  if ($ancestorCheck->isSuccessful()) {
   throw new RuntimeException('Pull request is already merged.');
  }

  $refName = str_starts_with($baseBranch, 'refs/') ? $baseBranch : 'refs/heads/' . $baseBranch;

  if ($strategy === 'fast-forward') {
   $ffCheck = new Process(['git', 'merge-base', '--is-ancestor', $baseSHA, $headSHA], $repo->path);
   $ffCheck->run();
   if (!$ffCheck->isSuccessful()) {
    throw new RuntimeException('Fast-forward merge not possible: base branch has diverged.');
   }

   $updateRef = new Process(['git', 'update-ref', $refName, $headSHA, $baseSHA], $repo->path);
   $updateRef->mustRun();
   return $headSHA;
  }

  // Strategies 'merge' and 'squash' use in-memory merge-tree
  $mergeTree = new Process(['git', 'merge-tree', '--write-tree', $baseSHA, $headSHA], $repo->path);
  $mergeTree->run();
  if (!$mergeTree->isSuccessful()) {
   throw new RuntimeException('Merge conflict detected.');
  }

  $treeSHA = trim(explode("\n", $mergeTree->getOutput())[0]);

  $env = [
   'GIT_AUTHOR_NAME' => $authorName,
   'GIT_AUTHOR_EMAIL' => $authorEmail,
   'GIT_COMMITTER_NAME' => $authorName,
   'GIT_COMMITTER_EMAIL' => $authorEmail,
  ];

  $commitArgs = ['git', 'commit-tree', $treeSHA];
  if ($strategy === 'merge') {
   $commitArgs[] = '-p';
   $commitArgs[] = $baseSHA;
   $commitArgs[] = '-p';
   $commitArgs[] = $headSHA;
  } else {
   // squash
   $commitArgs[] = '-p';
   $commitArgs[] = $baseSHA;
  }
  $commitArgs[] = '-m';
  $commitArgs[] = $message;

  $commitTree = new Process($commitArgs, $repo->path, $env);
  $commitTree->mustRun();
  $newCommitSHA = trim($commitTree->getOutput());

  $updateRef = new Process(['git', 'update-ref', $refName, $newCommitSHA, $baseSHA], $repo->path);
  $updateRef->mustRun();

  return $newCommitSHA;
 }

 /**
  * Generates a patch string using git format-patch --stdout.
  */
 public function formatPatch(GitRepositoryModel $repo, string $baseRef, string $headRef): string
 {
  $process = new Process(['git', 'format-patch', '--stdout', sprintf('%s..%s', $baseRef, $headRef)], $repo->path);
  $process->mustRun();

  return $process->getOutput();
 }

 /**
  * Installs executable pre-receive, update, post-receive, and post-update server hooks.
  * Generates primary hook dispatchers and .d/soft-serve hook scripts matching Go pkg/hooks/gen.go.
  */
 public function installServerHooks(GitRepositoryModel|string $repo, string $consoleBinPath): void
 {
  $repoPath = $repo instanceof GitRepositoryModel ? $repo->path : $repo;
  $hooksDir = $repoPath . '/hooks';
  if (!is_dir($hooksDir)) {
   if (!mkdir($hooksDir, 0755, true) && !is_dir($hooksDir)) {
    throw new RuntimeException(sprintf('Failed to create hooks directory: %s', $hooksDir));
   }
  }

  $hooks = ['pre-receive', 'update', 'post-receive', 'post-update'];
  foreach ($hooks as $hook) {
   $hookPath = $hooksDir . '/' . $hook;

   // 1. Primary hook dispatcher script (matching hookTemplate from pkg/hooks/gen.go)
   $hookTemplate = <<<'BASH'
#!/usr/bin/env bash
# AUTO GENERATED BY SOFT SERVE, DO NOT MODIFY
data=$(cat)
exitcodes=""
hookname=$(basename $0)
GIT_DIR=${GIT_DIR:-$(dirname $0)/..}
for hook in ${GIT_DIR}/hooks/${hookname}.d/*; do
  # Avoid running non-executable hooks
  test -x "${hook}" && test -f "${hook}" || continue

  # Run the actual hook
  echo "${data}" | "${hook}" "$@"

  # Store the exit code for later use
  exitcodes="${exitcodes} $?"
done

# Exit on the first non-zero exit code.
for i in ${exitcodes}; do
  [ ${i} -eq 0 ] || exit ${i}
done
BASH;
   file_put_contents($hookPath, $hookTemplate . "\n");
   chmod($hookPath, 0755);

   // 2. Create ${hook}.d directory
   $hookDDir = $hooksDir . '/' . $hook . '.d';
   if (!is_dir($hookDDir)) {
    if (!mkdir($hookDDir, 0755, true) && !is_dir($hookDDir)) {
     throw new RuntimeException(sprintf('Failed to create hook.d directory: %s', $hookDDir));
    }
   }

   // 3. Arguments matching Go switch hook
   $args = match ($hook) {
    'update' => '$1 $2 $3',
    'post-update' => '$@',
    default => '',
   };

   // 4. Soft-serve / codes.sh hook inside ${hook}.d (matching hooksTmpl from pkg/hooks/gen.go)
   $softHookPath = $hookDDir . '/soft-serve';
   $softHookContent = <<<BASH
#!/usr/bin/env bash
# AUTO GENERATED BY SOFT SERVE, DO NOT MODIFY
if [ -z "\$SOFT_SERVE_REPO_NAME" ]; then
    echo "Warning: SOFT_SERVE_REPO_NAME not defined. Skipping hooks."
    exit 0
fi
CONSOLE_BIN="{$consoleBinPath}"
exec "\$CONSOLE_BIN" soft:hook:{$hook} {$args}
BASH;
   file_put_contents($softHookPath, $softHookContent . "\n");
   chmod($softHookPath, 0755);
  }
 }

 /**
  * Resolves a branch name, ref, or hash to a 40-character commit SHA.
  */
 private function resolveCommitSha(GitRepositoryModel $repo, string $refOrSha): ?string
 {
  $candidates = [
   $refOrSha,
   'refs/heads/' . $refOrSha,
   'refs/tags/' . $refOrSha,
   $refOrSha . '^{commit}',
  ];

  foreach ($candidates as $cand) {
   $process = new Process(['git', 'rev-parse', '--verify', $cand], $repo->path);
   $process->run();
   if ($process->isSuccessful()) {
    $sha = trim($process->getOutput());
    if ($sha !== '') {
     return $sha;
    }
   }
  }

  return null;
 }
}

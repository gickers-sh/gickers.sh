<?php

declare(strict_types=1);

namespace App\Repo\Infrastructure\Git;

use App\Repo\Domain\Git\GitRepository;
use App\Repo\Domain\RepoDirectory;
use App\Repo\Domain\RepoName;
use App\Shared\Infrastructure\Config\ServerSettings;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Filesystem\Filesystem;

#[AsAlias(RepoDirectory::class)]
final class BareRepositoryStorage implements RepoDirectory
{
    public function __construct(
        private readonly ServerSettings $settings,
        private readonly ?GitRepositoryReader $reader = null,
    ) {
    }

    /**
     * Absolute path of `<repos>/<name>.git`, whether it exists or not.
     * Enforces path containment within the configured reposPath to prevent traversal.
     */
    public function pathFor(RepoName $name): string
    {
        $reposBase = rtrim(str_replace('\\', '/', $this->settings->reposPath()), '/');
        $val = str_replace('\\', '/', $name->value);

        // Reject directory traversal attempts
        if (str_contains($val, '..') || str_starts_with($val, '/')) {
            throw new \InvalidArgumentException('Path traversal is forbidden.');
        }

        $path = $reposBase.'/'.$val.'.git';

        if (!str_starts_with($path, $reposBase.'/')) {
            throw new \InvalidArgumentException(sprintf('Path "%s" escapes repository root "%s".', $path, $reposBase));
        }

        return $path;
    }

    /**
     * Checks if the bare repository exists on disk.
     */
    public function exists(RepoName $name): bool
    {
        $path = $this->pathFor($name);

        return is_dir($path) && (is_file($path.'/HEAD') || is_file($path.'/.git/HEAD'));
    }

    /**
     * Initializes a new bare repository under reposPath.
     */
    public function init(
        RepoName $name,
        string $defaultBranch = 'main',
        string $description = '',
    ): GitRepository {
        $path = $this->pathFor($name);
        if ($this->exists($name)) {
            throw new \RuntimeException(sprintf('Repository "%s" already exists.', $name->value));
        }

        $repo = $this->initBareRepository($path, $defaultBranch, $description);
        if ($repo->name !== $name->value) {
            return new GitRepository(
                name: $name->value,
                path: $repo->path,
                isBare: $repo->isBare,
                description: $repo->description,
                defaultBranch: $repo->defaultBranch,
                isPrivate: $repo->isPrivate,
                projectName: $repo->projectName,
                updatedAt: $repo->updatedAt,
            );
        }

        return $repo;
    }

    /**
     * Low-level bare repo initialization at an arbitrary path.
     */
    public function initBareRepository(
        string $path,
        string $defaultBranch = 'main',
        string $description = '',
    ): GitRepository {
        if (!is_dir($path)) {
            if (!mkdir($path, 0755, true) && !is_dir($path)) {
                throw new \RuntimeException(sprintf('Failed to create directory: %s', $path));
            }
        }

        $process = GitCommand::run(dirname($path), ['init', '--bare', '--initial-branch='.$defaultBranch, $path]);
        if (!$process->isSuccessful()) {
            GitCommand::mustRun(dirname($path), ['init', '--bare', $path]);
            GitCommand::mustRun($path, ['symbolic-ref', 'HEAD', new Revision('refs/heads/'.$defaultBranch)]);
        }

        if ('' !== $description) {
            file_put_contents($path.'/description', $description."\n");
        }

        $realPath = realpath($path) ?: $path;

        return ($this->reader ?? new GitRepositoryReader())->openRepository($realPath);
    }

    /**
     * Forks a bare repository: clones bare and removes origin remote.
     */
    public function fork(
        RepoName $source,
        RepoName $target,
        string $description = '',
    ): GitRepository {
        $sourcePath = $this->pathFor($source);
        if (!$this->exists($source)) {
            throw new \RuntimeException(sprintf('Source repository "%s" does not exist.', $source->value));
        }

        $targetPath = $this->pathFor($target);
        if ($this->exists($target)) {
            throw new \RuntimeException(sprintf('Target repository "%s" already exists.', $target->value));
        }

        $repo = $this->forkBareRepository($sourcePath, $targetPath, $description);
        if ($repo->name !== $target->value) {
            return new GitRepository(
                name: $target->value,
                path: $repo->path,
                isBare: $repo->isBare,
                description: $repo->description,
                defaultBranch: $repo->defaultBranch,
                isPrivate: $repo->isPrivate,
                projectName: $repo->projectName,
                updatedAt: $repo->updatedAt,
            );
        }

        return $repo;
    }

    /**
     * Low-level bare clone from sourcePath to targetPath.
     */
    public function forkBareRepository(
        string $sourcePath,
        string $targetPath,
        string $description = '',
    ): GitRepository {
        $parentDir = dirname($targetPath);
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }

        GitCommand::mustRun($parentDir, ['clone', '--bare', $sourcePath, $targetPath]);

        // Remove origin remote
        GitCommand::run($targetPath, ['remote', 'remove', 'origin']);

        if ('' !== $description) {
            file_put_contents($targetPath.'/description', $description."\n");
        }

        $realPath = realpath($targetPath) ?: $targetPath;

        return ($this->reader ?? new GitRepositoryReader())->openRepository($realPath);
    }

    /**
     * Clones a remote repository as a bare/mirror repository.
     */
    public function importMirror(string $remoteUrl, RepoName $target, bool $mirror): GitRepository
    {
        $targetPath = $this->pathFor($target);
        if ($this->exists($target)) {
            throw new \RuntimeException(sprintf('Target repository "%s" already exists.', $target->value));
        }

        $parentDir = dirname($targetPath);
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }

        $args = ['clone', '--bare'];
        if ($mirror) {
            $args[] = '--mirror';
        }
        $args[] = $remoteUrl;
        $args[] = $targetPath;

        GitCommand::mustRun($parentDir, $args);

        return $this->open($target);
    }

    /**
     * Renames a repository on disk.
     */
    public function rename(RepoName $from, RepoName $to): void
    {
        $fromPath = $this->pathFor($from);
        if (!$this->exists($from)) {
            throw new \RuntimeException(sprintf('Source repository "%s" does not exist.', $from->value));
        }
        $toPath = $this->pathFor($to);
        if ($this->exists($to)) {
            throw new \RuntimeException(sprintf('Target repository "%s" already exists.', $to->value));
        }

        $parentDir = dirname($toPath);
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }

        (new Filesystem())->rename($fromPath, $toPath);

        // Clean up empty parent directory if nested
        $fromParent = dirname($fromPath);
        $reposBase = rtrim($this->settings->reposPath(), '/');
        if ($fromParent !== $reposBase && is_dir($fromParent)) {
            @rmdir($fromParent);
        }
    }

    /**
     * Deletes a repository directory from disk.
     */
    public function delete(RepoName $name): void
    {
        $path = $this->pathFor($name);
        if (is_dir($path)) {
            (new Filesystem())->remove($path);
        }

        // Clean up empty parent directory if nested
        $parent = dirname($path);
        $reposBase = rtrim($this->settings->reposPath(), '/');
        if ($parent !== $reposBase && is_dir($parent)) {
            @rmdir($parent);
        }
    }

    /**
     * Opens an existing repository.
     */
    public function open(RepoName $name): GitRepository
    {
        $path = $this->pathFor($name);
        if (!$this->exists($name)) {
            throw new \RuntimeException(sprintf('Repository "%s" does not exist.', $name->value));
        }

        $repo = ($this->reader ?? new GitRepositoryReader())->openRepository($path);
        if ($repo->name !== $name->value) {
            return new GitRepository(
                name: $name->value,
                path: $repo->path,
                isBare: $repo->isBare,
                description: $repo->description,
                defaultBranch: $repo->defaultBranch,
                isPrivate: $repo->isPrivate,
                projectName: $repo->projectName,
                updatedAt: $repo->updatedAt,
            );
        }

        return $repo;
    }
}

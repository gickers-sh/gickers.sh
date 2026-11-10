<?php

declare(strict_types=1);

namespace App\Repo\Infrastructure\Release;

use App\Repo\Domain\Release\ReleaseStore;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Infrastructure\Config\ServerSettings;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Filesystem storage for release artifacts, kept outside the bare repositories at
 * `<dataPath>/releases/<repo>/<tag>/<filename>`. Tag and filename are single path
 * components: names containing separators or `..` are rejected, and an existing
 * artifact is never overwritten.
 */
#[AsAlias(ReleaseStore::class)]
final readonly class ReleaseStorage implements ReleaseStore
{
    public function __construct(
        private ServerSettings $settings,
    ) {
    }

    public function exists(RepoName $repo, string $tag, string $filename): bool
    {
        return is_file($this->filePath($repo, $tag, $filename));
    }

    /**
     * Writes a new artifact. Throws {@see InvalidInput} on an unsafe tag/filename
     * and {@see \RuntimeException} when the artifact already exists.
     */
    public function store(RepoName $repo, string $tag, string $filename, string $contents): void
    {
        $this->assertSafeComponent($tag, 'tag');
        $this->assertSafeComponent($filename, 'filename');

        $path = $this->filePath($repo, $tag, $filename);
        if (is_file($path)) {
            throw new \RuntimeException(sprintf('release artifact "%s" already exists for tag "%s"', $filename, $tag));
        }

        $dir = \dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('failed to create release directory "%s"', $dir));
        }

        if (false === file_put_contents($path, $contents)) {
            throw new \RuntimeException(sprintf('failed to write release artifact "%s"', $path));
        }
    }

    /**
     * Absolute path of an existing artifact, or null when the tag/filename is
     * unsafe or the artifact does not exist. Guards against escaping the releases
     * root even if a component slips through.
     */
    public function pathFor(RepoName $repo, string $tag, string $filename): ?string
    {
        try {
            $this->assertSafeComponent($tag, 'tag');
            $this->assertSafeComponent($filename, 'filename');
        } catch (InvalidInput) {
            return null;
        }

        $path = $this->filePath($repo, $tag, $filename);
        $real = realpath($path);
        $base = realpath($this->base());
        if (false === $real || false === $base || !str_starts_with($real, $base.'/') || !is_file($real)) {
            return null;
        }

        return $real;
    }

    private function base(): string
    {
        return rtrim($this->settings->dataPath, '/').'/releases';
    }

    private function filePath(RepoName $repo, string $tag, string $filename): string
    {
        return sprintf('%s/%s/%s/%s', $this->base(), $repo->value, $tag, $filename);
    }

    private function assertSafeComponent(string $name, string $label): void
    {
        if ('' === $name || '.' === $name || '..' === $name) {
            throw new InvalidInput(sprintf('invalid %s', $label));
        }
        if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, '..')) {
            throw new InvalidInput(sprintf('%s cannot contain path separators or ".."', $label));
        }
        if (1 === preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw new InvalidInput(sprintf('%s contains control characters', $label));
        }
    }
}

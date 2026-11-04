<?php

declare(strict_types=1);

namespace App\Lfs\Infrastructure;

use App\Lfs\Domain\Oid;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Infrastructure\Config\ServerSettings;

/**
 * Content-addressable storage for LFS objects on the local filesystem, one tree
 * per repository: `%data%/lfs/<repoId>/objects/<oid[0:2]>/<oid[2:4]>/<oid>`
 * (Go `pkg/storage.LocalStorage` rooted at `<dataPath>/lfs/<repoID>`).
 *
 * Uploads are streamed and hashed incrementally, never buffered whole.
 */
final class FilesystemObjectStorage
{
    public function __construct(private readonly ServerSettings $settings) {}

    public function path(int $repoId, Oid $oid): string
    {
        return sprintf('%s/%d/objects/%s', rtrim($this->settings->lfsPath(), '/'), $repoId, $oid->relativePath());
    }

    public function exists(int $repoId, Oid $oid): bool
    {
        return is_file($this->path($repoId, $oid));
    }

    /**
     * On-disk byte size of a stored object, or null if absent.
     */
    public function size(int $repoId, Oid $oid): ?int
    {
        $path = $this->path($repoId, $oid);
        if (!is_file($path)) {
            return null;
        }
        $size = filesize($path);

        return false === $size ? null : $size;
    }

    /**
     * Opens a stored object for reading.
     *
     * @return resource
     *
     * @throws \RuntimeException when the object does not exist or cannot be opened
     */
    public function openRead(int $repoId, Oid $oid)
    {
        $path = $this->path($repoId, $oid);
        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            throw new \RuntimeException(sprintf('LFS object "%s" not found', $oid->value));
        }

        return $handle;
    }

    /**
     * Streams $input into storage, verifying the sha256 and (when $expectedSize >= 0)
     * the byte count before atomically publishing the object. Returns the stored size.
     *
     * @param resource $input
     *
     * @throws InvalidInput     when the hash or size does not match
     * @throws \RuntimeException on filesystem errors
     */
    public function store(int $repoId, Oid $expectedOid, int $expectedSize, $input): int
    {
        $target = $this->path($repoId, $expectedOid);
        $dir = \dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Failed to create LFS storage directory "%s"', $dir));
        }

        $tmp = sprintf('%s/.tmp.%s.%s', $dir, $expectedOid->value, bin2hex(random_bytes(6)));
        $out = @fopen($tmp, 'wb');
        if (false === $out) {
            throw new \RuntimeException(sprintf('Failed to open temporary LFS file "%s"', $tmp));
        }

        $hash = hash_init('sha256');
        $size = 0;
        try {
            while (!feof($input)) {
                $chunk = fread($input, 1 << 20);
                if (false === $chunk) {
                    throw new \RuntimeException('Failed reading LFS upload stream');
                }
                if ('' === $chunk) {
                    continue;
                }
                $size += \strlen($chunk);
                hash_update($hash, $chunk);
                if (false === fwrite($out, $chunk)) {
                    throw new \RuntimeException(sprintf('Failed writing temporary LFS file "%s"', $tmp));
                }
            }
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($tmp);
            throw $e;
        }
        fclose($out);

        $actualOid = hash_final($hash);
        if ($actualOid !== $expectedOid->value) {
            @unlink($tmp);
            throw new InvalidInput(sprintf('LFS SHA-256 mismatch for oid %s: calculated %s', $expectedOid->value, $actualOid));
        }
        if ($expectedSize >= 0 && $size !== $expectedSize) {
            @unlink($tmp);
            throw new InvalidInput(sprintf('LFS size mismatch for oid %s: expected %d bytes, got %d bytes', $expectedOid->value, $expectedSize, $size));
        }

        if (!rename($tmp, $target)) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf('Failed to publish LFS object "%s"', $target));
        }

        return $size;
    }

    /**
     * Deletes the whole LFS tree of a repository (used by DeleteRepo).
     */
    public function removeRepository(int $repoId): void
    {
        $dir = sprintf('%s/%d', rtrim($this->settings->lfsPath(), '/'), $repoId);
        if (is_dir($dir)) {
            $this->rrmdir($dir);
        }
    }

    private function rrmdir(string $dir): void
    {
        $entries = scandir($dir);
        if (false === $entries) {
            return;
        }
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

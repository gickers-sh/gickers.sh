<?php

declare(strict_types=1);

namespace App\Tests\Integration\CodeIntel;

use App\CodeIntel\Infrastructure\FilesystemIndexStore;
use App\CodeIntel\Infrastructure\Scip\ScipProtobufCodec;
use App\Shared\Infrastructure\Config\ServerSettings;
use PHPUnit\Framework\TestCase;

/**
 * Base for CodeIntel tests. These are pure (no database): SCIP indices live under throwaway
 * temp directories, so a hand-built {@see FilesystemIndexStore} with a temp-rooted
 * {@see ServerSettings} fully exercises the store/query/indexer behaviour.
 */
abstract class CodeIntelTestCase extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }
        $this->tempDirs = [];
    }

    protected function createTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(6));
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * Builds a {@see FilesystemIndexStore} rooted at a fresh throwaway data path.
     */
    protected function makeStore(?ScipProtobufCodec $codec = null): FilesystemIndexStore
    {
        $dataPath = $this->createTempDir('codeintel_data_');

        return new FilesystemIndexStore($codec ?? new ScipProtobufCodec(), $this->settingsFor($dataPath));
    }

    protected function settingsFor(string $dataPath): ServerSettings
    {
        return new ServerSettings(
            name: 'test-server',
            dataPath: $dataPath,
            sshListenAddr: '127.0.0.1:2222',
            sshPublicUrl: 'ssh://localhost:2222',
            httpListenAddr: '127.0.0.1:23232',
            httpPublicUrl: 'http://localhost:23232',
            gitListenAddr: '127.0.0.1:9418',
            gitMaxConnections: 32,
            lfsEnabled: true,
            anonAccess: 'read-only',
            allowKeyless: false,
            initialAdminKeys: '',
        );
    }

    protected function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $fileinfo) {
                if ($fileinfo->isDir()) {
                    @rmdir($fileinfo->getRealPath());
                } else {
                    @unlink($fileinfo->getRealPath());
                }
            }
            @rmdir($dir);
        } catch (\Throwable) {
            // Ignore cleanup errors.
        }
    }
}

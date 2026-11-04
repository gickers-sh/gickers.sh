<?php

declare(strict_types=1);

namespace App\CodeIntel\Infrastructure;

use Symfony\Component\Process\Process;

/**
 * Exports a commit's tree from a bare repository into a fresh temporary directory
 * (`git archive --format=tar <sha>` + extraction), ported from the legacy
 * CodeIntelService::indexCommit archive steps.
 */
final class GitArchiveExtractor
{
    /**
     * Extracts the tree of $sha from the repository at $repoPath into a new temp directory,
     * whose path is returned. The caller owns the directory and must {@see cleanup()} it.
     *
     * @throws \RuntimeException when the archive cannot be produced or extracted
     */
    public function extract(string $repoPath, string $sha): string
    {
        $tmpDir = sys_get_temp_dir() . '/codes_sh_codeintel_' . bin2hex(random_bytes(8));
        if (!mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
            throw new \RuntimeException('Failed to create temporary directory for codeintel indexing');
        }

        $archiveProcess = new Process(['git', 'archive', '--format=tar', $sha], $repoPath);
        $archiveProcess->run();
        if (!$archiveProcess->isSuccessful()) {
            $this->cleanup($tmpDir);

            throw new \RuntimeException('git archive failed: ' . $archiveProcess->getErrorOutput());
        }

        $tarFile = $tmpDir . '/archive.tar';
        file_put_contents($tarFile, $archiveProcess->getOutput());

        $extracted = false;
        try {
            $phar = new \PharData($tarFile);
            $phar->extractTo($tmpDir);
            $extracted = true;
        } catch (\Throwable) {
            // Fallback to the system tar when PharData cannot read git's archive format.
            $extractProcess = new Process(['tar', '-xf', $tarFile, '-C', $tmpDir]);
            $extractProcess->run();
            if ($extractProcess->isSuccessful()) {
                $extracted = true;
            }
        }

        @unlink($tarFile);
        if (!$extracted) {
            $this->cleanup($tmpDir);

            throw new \RuntimeException('Failed to extract git archive tarball');
        }

        return $tmpDir;
    }

    /**
     * Best-effort recursive removal of a directory produced by {@see extract()}.
     */
    public function cleanup(string $dir): void
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
            // Best effort.
        }
    }
}

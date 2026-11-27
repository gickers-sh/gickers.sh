<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repo\Domain\Git\GitRepository;
use Symfony\Component\Process\Process;

final class GitTestHelper
{
    public static function writeFile(
        GitRepository $repo,
        string $branch,
        string $filePath,
        string $content,
        string $message = 'commit',
        string $authorName = 'Tester',
        string $authorEmail = 'test@gickers.sh',
    ): string {
        $hashProc = new Process(['git', 'hash-object', '-w', '--stdin'], $repo->path, input: $content);
        $hashProc->mustRun();
        $blobHash = trim($hashProc->getOutput());

        $tempIndex = tempnam(sys_get_temp_dir(), 'git_test_idx_');
        if (false !== $tempIndex) {
            @unlink($tempIndex);
        }
        $env = [
            'GIT_INDEX_FILE' => $tempIndex,
            'GIT_AUTHOR_NAME' => $authorName,
            'GIT_AUTHOR_EMAIL' => $authorEmail,
            'GIT_COMMITTER_NAME' => $authorName,
            'GIT_COMMITTER_EMAIL' => $authorEmail,
        ];

        try {
            $parent = null;
            $checkParent = new Process(['git', 'rev-parse', '--verify', $branch], $repo->path);
            if (0 === $checkParent->run()) {
                $parent = trim($checkParent->getOutput());
                (new Process(['git', 'read-tree', $parent], $repo->path, $env))->mustRun();
            }

            (new Process(['git', 'update-index', '--add', '--cacheinfo', sprintf('100644,%s,%s', $blobHash, $filePath)], $repo->path, $env))->mustRun();
            $treeProc = new Process(['git', 'write-tree'], $repo->path, $env);
            $treeProc->mustRun();
            $treeHash = trim($treeProc->getOutput());

            $commitArgs = ['commit-tree', $treeHash, '-m', $message];
            if (null !== $parent) {
                $commitArgs[] = '-p';
                $commitArgs[] = $parent;
            }
            $commitProc = new Process(['git', ...$commitArgs], $repo->path, $env);
            $commitProc->mustRun();
            $commitHash = trim($commitProc->getOutput());

            $ref = str_starts_with($branch, 'refs/') ? $branch : 'refs/heads/'.$branch;
            (new Process(['git', 'update-ref', $ref, $commitHash], $repo->path))->mustRun();

            if (!$repo->isBare) {
                $dest = $repo->path.'/'.$filePath;
                $destDir = dirname($dest);
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0755, true);
                }
                file_put_contents($dest, $content);
            }

            return $commitHash;
        } finally {
            if (false !== $tempIndex && file_exists($tempIndex)) {
                unlink($tempIndex);
            }
        }
    }
}

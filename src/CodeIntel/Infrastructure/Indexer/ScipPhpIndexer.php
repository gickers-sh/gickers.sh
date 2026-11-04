<?php

declare(strict_types=1);

namespace App\CodeIntel\Infrastructure\Indexer;

use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Indexes PHP projects with the external `scip-php` tool when available. Ranked above the native
 * fallback so a real scip-php install is preferred; returns null when scip-php is missing or fails,
 * letting the handler fall through to {@see NativePhpIndexer}.
 *
 * Deviation: the executable search PATH is injected via #[Autowire(env: 'PATH')] to keep src/
 * free of getenv().
 */
#[AsTaggedItem(priority: 20)]
final class ScipPhpIndexer implements Indexer
{
    public function __construct(
        #[Autowire(env: 'PATH')]
        private readonly string $pathEnv = '',
    ) {
    }

    public function supports(string $projectDir): bool
    {
        return NativePhpIndexer::isPhpProject($projectDir);
    }

    public function index(string $projectDir): ?string
    {
        $scipPhp = $this->findExecutable('scip-php');
        if (null === $scipPhp) {
            return null;
        }

        $outputIndex = $projectDir.'/index.scip';
        $cmd = new Process([$scipPhp], $projectDir);
        $cmd->run();
        if (!$cmd->isSuccessful() || !file_exists($outputIndex)) {
            return null;
        }

        $binary = @file_get_contents($outputIndex);

        return false === $binary ? null : $binary;
    }

    private function findExecutable(string $name): ?string
    {
        foreach (explode(\PATH_SEPARATOR, $this->pathEnv) as $dir) {
            if ('' === $dir) {
                continue;
            }
            $candidate = rtrim($dir, '/\\').\DIRECTORY_SEPARATOR.$name;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}

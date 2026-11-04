<?php

declare(strict_types=1);

namespace App\CodeIntel\Infrastructure\Indexer;

use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Indexes Go projects (detected by `go.mod`) with the external `scip-go` tool when available.
 * Highest priority so Go is tried before any PHP indexer.
 *
 * Deviation: the executable search PATH is injected via #[Autowire(env: 'PATH')] so src/ stays
 * free of getenv(); returns null when scip-go is absent (no native Go fallback, as in the legacy).
 */
#[AsTaggedItem(priority: 30)]
final class ScipGoIndexer implements Indexer
{
    public function __construct(
        #[Autowire(env: 'PATH')]
        private readonly string $pathEnv = '',
    ) {
    }

    public function supports(string $projectDir): bool
    {
        return file_exists($projectDir.'/go.mod');
    }

    public function index(string $projectDir): ?string
    {
        $scipGo = $this->findExecutable('scip-go');
        if (null === $scipGo) {
            return null;
        }

        $outputIndex = $projectDir.'/index.scip';
        $cmd = new Process([$scipGo, '--output', $outputIndex], $projectDir);
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

<?php

declare(strict_types=1);

namespace App\CodeIntel\Infrastructure;

use App\CodeIntel\Domain\IndexStore;
use App\CodeIntel\Domain\Occurrence;
use App\CodeIntel\Domain\SymbolLocation;
use App\CodeIntel\Infrastructure\Scip\ScipProtobufCodec;
use App\Shared\Infrastructure\Config\ServerSettings;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Filesystem-backed {@see IndexStore}: persists binary SCIP indices under the code-intel
 * data directory and caches their decoded structure in memory.
 *
 * Deviation: the storage root is {@see ServerSettings::codeIntelPath()} (config-driven) rather
 * than the legacy CodeIntelService hard-coded `var/codeintel` default.
 */
#[AsAlias(IndexStore::class)]
final class FilesystemIndexStore implements IndexStore
{
    private readonly LoggerInterface $logger;

    /**
     * In-memory cache of parsed SCIP indices, keyed `"$repo:$sha"`.
     *
     * @var array<string, array{docOccurrences: array<string, Occurrence[]>, definitions: array<string, SymbolLocation>, references: array<string, SymbolLocation[]>}>
     */
    private array $cache = [];

    public function __construct(
        private readonly ScipProtobufCodec $codec,
        private readonly ServerSettings $settings,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function has(string $repo, string $sha): bool
    {
        if (isset($this->cache[$this->cacheKey($repo, $sha)])) {
            return true;
        }

        return file_exists($this->pathFor($repo, $sha));
    }

    public function store(string $repo, string $sha, string $scipBinary): void
    {
        $filePath = $this->pathFor($repo, $sha);
        $dir = \dirname($filePath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Failed to create storage directory "%s"', $dir));
        }

        if (false === file_put_contents($filePath, $scipBinary)) {
            throw new \RuntimeException(sprintf('Failed to write SCIP index to "%s"', $filePath));
        }

        $this->cache[$this->cacheKey($repo, $sha)] = $this->parseIndex($scipBinary);
    }

    public function get(string $repo, string $sha): ?array
    {
        $cacheKey = $this->cacheKey($repo, $sha);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $filePath = $this->pathFor($repo, $sha);
        if (!file_exists($filePath)) {
            return null;
        }

        $binary = @file_get_contents($filePath);
        if (false === $binary) {
            $this->logger->warning('Failed to read stored SCIP index', ['path' => $filePath]);

            return null;
        }

        $parsed = $this->parseIndex($binary);
        $this->cache[$cacheKey] = $parsed;

        return $parsed;
    }

    public function pathFor(string $repo, string $sha): string
    {
        $repoClean = trim(str_replace(['..', '\\'], ['', '/'], $repo), '/');

        return $this->settings->codeIntelPath().'/'.$repoClean.'/'.$sha.'.scip';
    }

    /**
     * Decodes a binary SCIP index into query-ready maps, preserving the exact legacy semantics:
     * empty and `local ` symbols are skipped, definitions are first-wins, references are
     * deduplicated by `symbol|path|startLine|startCol`, and each document's occurrences are
     * sorted by (startLine, startCol). Documents with no kept occurrences retain an empty list.
     *
     * @return array{docOccurrences: array<string, Occurrence[]>, definitions: array<string, SymbolLocation>, references: array<string, SymbolLocation[]>}
     */
    public function parseIndex(string $scipBinary): array
    {
        $decoded = $this->codec->decodeIndex($scipBinary);

        /** @var array<string, Occurrence[]> $docOccurrences */
        $docOccurrences = [];
        /** @var array<string, SymbolLocation> $definitions */
        $definitions = [];
        /** @var array<string, SymbolLocation[]> $references */
        $references = [];
        /** @var array<string, bool> $seenRefs */
        $seenRefs = [];

        foreach ($decoded['documents'] as $doc) {
            $rawPath = (string) ($doc['relative_path'] ?? '');
            $cleanPath = $this->cleanPath($rawPath);
            if (!isset($docOccurrences[$cleanPath])) {
                $docOccurrences[$cleanPath] = [];
            }

            foreach ($doc['occurrences'] ?? [] as $occ) {
                $symbol = (string) ($occ['symbol'] ?? '');
                // Skip empty symbols and local scoped symbols ("local ").
                if ('' === $symbol || str_starts_with($symbol, 'local ')) {
                    continue;
                }

                $rangeCoords = ScipProtobufCodec::parseRange($occ['range'] ?? []);
                $roles = (int) ($occ['symbol_roles'] ?? 0);
                $isDef = ($roles & ScipProtobufCodec::SYMBOL_ROLE_DEFINITION) !== 0;

                $docOccurrences[$cleanPath][] = new Occurrence(
                    startLine: $rangeCoords['startLine'],
                    startCol: $rangeCoords['startCol'],
                    endLine: $rangeCoords['endLine'],
                    endCol: $rangeCoords['endCol'],
                    symbol: $symbol,
                    isDefinition: $isDef,
                );

                $loc = new SymbolLocation(
                    path: $cleanPath,
                    line: $rangeCoords['startLine'],
                    col: $rangeCoords['startCol'],
                    endLine: $rangeCoords['endLine'],
                    endCol: $rangeCoords['endCol'],
                );

                if ($isDef) {
                    if (!isset($definitions[$symbol])) {
                        $definitions[$symbol] = $loc;
                    }
                } else {
                    $refKey = $symbol.'|'.$cleanPath.'|'.$rangeCoords['startLine'].'|'.$rangeCoords['startCol'];
                    if (!isset($seenRefs[$refKey])) {
                        $seenRefs[$refKey] = true;
                        if (!isset($references[$symbol])) {
                            $references[$symbol] = [];
                        }
                        $references[$symbol][] = $loc;
                    }
                }
            }

            // Sort occurrences by (startLine, startCol).
            usort($docOccurrences[$cleanPath], static function (Occurrence $a, Occurrence $b): int {
                if ($a->startLine !== $b->startLine) {
                    return $a->startLine <=> $b->startLine;
                }

                return $a->startCol <=> $b->startCol;
            });
        }

        return [
            'docOccurrences' => $docOccurrences,
            'definitions' => $definitions,
            'references' => $references,
        ];
    }

    private function cleanPath(string $p): string
    {
        $p = str_replace('\\', '/', $p);
        $p = ltrim($p, './');
        $p = preg_replace('#/{2,}#', '/', $p) ?? $p;

        return trim($p, '/');
    }

    private function cacheKey(string $repo, string $sha): string
    {
        return $repo.':'.$sha;
    }
}

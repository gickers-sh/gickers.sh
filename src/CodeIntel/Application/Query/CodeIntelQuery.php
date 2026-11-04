<?php

declare(strict_types=1);

namespace App\CodeIntel\Application\Query;

use App\CodeIntel\Domain\IndexStore;
use App\CodeIntel\Domain\Occurrence;
use App\CodeIntel\Domain\SymbolInfo;
use App\CodeIntel\Domain\SymbolLocation;

/**
 * Read-side code-intelligence queries over the stored SCIP index of a commit.
 *
 * `repo` is the bare repository name (e.g. "demo"), `sha` the commit SHA, `path` a
 * repo-relative file path, and line/col are 0-indexed to match {@see Occurrence}.
 * When no index is present, {@see has()} returns false and every finder returns null/[]
 * without throwing.
 *
 * Deviation: the query surface (has/occurrences) is renamed from the legacy
 * CodeIntelService (hasIndex/getDocumentOccurrences) to match the TUI consumer contract.
 */
final readonly class CodeIntelQuery
{
    public function __construct(private IndexStore $store)
    {
    }

    public function has(string $repo, string $sha): bool
    {
        return $this->store->has($repo, $sha);
    }

    /**
     * @return list<Occurrence>
     */
    public function occurrences(string $repo, string $sha, string $path): array
    {
        $idx = $this->store->get($repo, $sha);
        if (null === $idx) {
            return [];
        }

        return array_values($idx['docOccurrences'][$this->cleanPath($path)] ?? []);
    }

    /**
     * Identifies the symbol at the given 0-indexed (line, col) cursor position.
     */
    public function symbolAt(string $repo, string $sha, string $path, int $line, int $col): ?string
    {
        foreach ($this->occurrences($repo, $sha, $path) as $occ) {
            if ($occ->contains($line, $col)) {
                return $occ->symbol;
            }
        }

        return null;
    }

    /**
     * Finds the definition location of the symbol under (line, col) in $path.
     */
    public function findDefinition(string $repo, string $sha, string $path, int $line, int $col): ?SymbolLocation
    {
        $idx = $this->store->get($repo, $sha);
        if (null === $idx) {
            return null;
        }

        $symbol = $this->symbolAt($repo, $sha, $path, $line, $col);
        if (null === $symbol) {
            return null;
        }

        return $idx['definitions'][$symbol] ?? null;
    }

    /**
     * Finds all referencing locations for the symbol under (line, col) in $path.
     *
     * @return list<SymbolLocation>
     */
    public function findReferences(string $repo, string $sha, string $path, int $line, int $col): array
    {
        $idx = $this->store->get($repo, $sha);
        if (null === $idx) {
            return [];
        }

        $symbol = $this->symbolAt($repo, $sha, $path, $line, $col);
        if (null === $symbol) {
            return [];
        }

        return array_values($idx['references'][$symbol] ?? []);
    }

    /**
     * Aggregates the definition and references of a symbol, or null when the symbol is
     * absent (neither defined nor referenced).
     */
    public function symbolInfo(string $repo, string $sha, string $symbol): ?SymbolInfo
    {
        $def = $this->findDefinitionForSymbol($repo, $sha, $symbol);
        $refs = $this->findReferencesForSymbol($repo, $sha, $symbol);

        $defs = null !== $def ? [$def] : [];
        if ([] === $defs && [] === $refs) {
            return null;
        }

        return new SymbolInfo($symbol, $defs, $refs);
    }

    private function findDefinitionForSymbol(string $repo, string $sha, string $symbol): ?SymbolLocation
    {
        $idx = $this->store->get($repo, $sha);
        if (null === $idx || '' === $symbol) {
            return null;
        }

        return $idx['definitions'][$symbol] ?? null;
    }

    /**
     * @return list<SymbolLocation>
     */
    private function findReferencesForSymbol(string $repo, string $sha, string $symbol): array
    {
        $idx = $this->store->get($repo, $sha);
        if (null === $idx || '' === $symbol) {
            return [];
        }

        return array_values($idx['references'][$symbol] ?? []);
    }

    /**
     * Mirrors the store's path normalization so lookups match the keys built during decoding.
     */
    private function cleanPath(string $p): string
    {
        $p = str_replace('\\', '/', $p);
        $p = ltrim($p, './');
        $p = preg_replace('#/{2,}#', '/', $p) ?? $p;

        return trim($p, '/');
    }
}

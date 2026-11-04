<?php

declare(strict_types=1);

namespace App\CodeIntel\Domain;

/**
 * Persistent store of decoded SCIP indices keyed by repository name and commit SHA.
 */
interface IndexStore
{
    /**
     * Whether an index is available (in memory or on disk).
     */
    public function has(string $repo, string $sha): bool;

    /**
     * Persists a binary SCIP index and caches its decoded structure.
     */
    public function store(string $repo, string $sha, string $scipBinary): void;

    /**
     * Returns the decoded index structure, or null when none is stored.
     *
     * @return array{docOccurrences: array<string, Occurrence[]>, definitions: array<string, SymbolLocation>, references: array<string, SymbolLocation[]>}|null
     */
    public function get(string $repo, string $sha): ?array;

    /**
     * Absolute filesystem path of the stored `.scip` binary, whether it exists or not.
     */
    public function pathFor(string $repo, string $sha): string;
}

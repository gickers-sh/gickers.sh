<?php

declare(strict_types=1);

namespace App\CodeIntel\Domain;

/**
 * Aggregated definitions and references for a single symbol.
 */
final readonly class SymbolInfo
{
    /**
     * @param SymbolLocation[] $definitions
     * @param SymbolLocation[] $references
     */
    public function __construct(
        public string $symbol,
        public array $definitions = [],
        public array $references = [],
    ) {
    }

    public function hasDefinition(): bool
    {
        return !empty($this->definitions);
    }

    public function primaryDefinition(): ?SymbolLocation
    {
        return $this->definitions[0] ?? null;
    }

    public function referenceCount(): int
    {
        return count($this->references);
    }

    /**
     * @return array{symbol: string, definitions: array<int, array{path: string, line: int, col: int, endLine: ?int, endCol: ?int}>, references: array<int, array{path: string, line: int, col: int, endLine: ?int, endCol: ?int}>}
     */
    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'definitions' => array_map(static fn (SymbolLocation $loc) => $loc->toArray(), $this->definitions),
            'references' => array_map(static fn (SymbolLocation $loc) => $loc->toArray(), $this->references),
        ];
    }
}

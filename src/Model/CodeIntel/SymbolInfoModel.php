<?php

declare(strict_types=1);

namespace App\Model\CodeIntel;

final class SymbolInfoModel
{
    /**
     * @param SymbolLocationModel[] $definitions
     * @param SymbolLocationModel[] $references
     */
    public function __construct(
        public readonly string $symbol,
        public readonly array $definitions = [],
        public readonly array $references = [],
    ) {
    }

    public function hasDefinition(): bool
    {
        return !empty($this->definitions);
    }

    public function primaryDefinition(): ?SymbolLocationModel
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
            'definitions' => array_map(static fn (SymbolLocationModel $loc) => $loc->toArray(), $this->definitions),
            'references' => array_map(static fn (SymbolLocationModel $loc) => $loc->toArray(), $this->references),
        ];
    }
}

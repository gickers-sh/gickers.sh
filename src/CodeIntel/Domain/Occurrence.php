<?php

declare(strict_types=1);

namespace App\CodeIntel\Domain;

/**
 * A single symbol occurrence inside a document, with its 0-indexed source range.
 */
final readonly class Occurrence
{
    public function __construct(
        public int $startLine,
        public int $startCol,
        public int $endLine,
        public int $endCol,
        public string $symbol,
        public bool $isDefinition,
    ) {
    }

    /**
     * Checks whether a 0-indexed (line, col) cursor falls within this occurrence range.
     * Front boundaries are inclusive and end column boundaries are exclusive: [startCol, endCol).
     */
    public function contains(int $line, int $col): bool
    {
        if ($line < $this->startLine || $line > $this->endLine) {
            return false;
        }

        if ($line === $this->startLine && $col < $this->startCol) {
            return false;
        }

        if ($line === $this->endLine && $col >= $this->endCol) {
            return false;
        }

        return true;
    }

    /**
     * @return array{startLine: int, startCol: int, endLine: int, endCol: int, symbol: string, isDefinition: bool}
     */
    public function toArray(): array
    {
        return [
            'startLine' => $this->startLine,
            'startCol' => $this->startCol,
            'endLine' => $this->endLine,
            'endCol' => $this->endCol,
            'symbol' => $this->symbol,
            'isDefinition' => $this->isDefinition,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\CodeIntel\Domain;

/**
 * A resolved source location (definition or reference) for a symbol.
 */
final readonly class SymbolLocation implements \Stringable
{
    public function __construct(
        public string $path,
        public int $line,
        public int $col,
        public ?int $endLine = null,
        public ?int $endCol = null,
    ) {
    }

    /**
     * @return array{path: string, line: int, col: int, endLine: ?int, endCol: ?int}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'line' => $this->line,
            'col' => $this->col,
            'endLine' => $this->endLine,
            'endCol' => $this->endCol,
        ];
    }

    public function __toString(): string
    {
        return sprintf('%s:%d:%d', $this->path, $this->line, $this->col);
    }
}

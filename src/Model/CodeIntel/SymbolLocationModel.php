<?php

declare(strict_types=1);

namespace App\Model\CodeIntel;

final class SymbolLocationModel
{
    public function __construct(
        public readonly string $path,
        public readonly int $line,
        public readonly int $col,
        public readonly ?int $endLine = null,
        public readonly ?int $endCol = null,
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

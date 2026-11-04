<?php

declare(strict_types=1);

namespace CodesSh\Tui\Style;

final readonly class BorderDefinition
{
    public function __construct(
        public string $top = '─',
        public string $bottom = '─',
        public string $left = '│',
        public string $right = '│',
        public string $topLeft = '┌',
        public string $topRight = '┐',
        public string $bottomLeft = '└',
        public string $bottomRight = '┘',
    ) {
    }
}

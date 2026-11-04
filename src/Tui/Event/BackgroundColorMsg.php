<?php

declare(strict_types=1);

namespace App\Tui\Event;

final readonly class BackgroundColorMsg implements InputEvent
{
    public function __construct(
        public int $r,
        public int $g,
        public int $b,
        public bool $isDark
    ) {
    }
}

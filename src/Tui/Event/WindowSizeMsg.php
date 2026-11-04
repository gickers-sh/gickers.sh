<?php

declare(strict_types=1);

namespace App\Tui\Event;

final readonly class WindowSizeMsg implements InputEvent
{
    public function __construct(
        public int $width,
        public int $height
    ) {
    }
}

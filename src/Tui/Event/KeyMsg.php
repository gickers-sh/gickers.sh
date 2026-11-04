<?php

declare(strict_types=1);

namespace App\Tui\Event;

final readonly class KeyMsg implements InputEvent
{
    public const UP = 'up';
    public const DOWN = 'down';
    public const LEFT = 'left';
    public const RIGHT = 'right';
    public const ENTER = 'enter';
    public const ESC = 'esc';
    public const TAB = 'tab';
    public const BACKTAB = 'shift+tab';
    public const BACKSPACE = 'backspace';
    public const DELETE = 'delete';
    public const PAGE_UP = 'pgup';
    public const PAGE_DOWN = 'pgdown';
    public const HOME = 'home';
    public const END = 'end';

    public function __construct(
        public string $key,
        public string $runes = '',
        public bool $alt = false,
        public bool $ctrl = false
    ) {
    }

    public function is(string $expected): bool
    {
        return $this->key === $expected;
    }

    public function __toString(): string
    {
        return $this->key;
    }
}

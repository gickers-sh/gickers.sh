<?php

declare(strict_types=1);

namespace CodesSh\Tui\Event;

final readonly class MouseMsg implements InputEvent
{
    public const ACTION_PRESS = 'press';
    public const ACTION_RELEASE = 'release';
    public const ACTION_MOTION = 'motion';

    public const BUTTON_LEFT = 0;
    public const BUTTON_MIDDLE = 1;
    public const BUTTON_RIGHT = 2;
    public const BUTTON_NONE = 3;
    public const BUTTON_WHEEL_UP = 64;
    public const BUTTON_WHEEL_DOWN = 65;

    public function __construct(
        public int $x,
        public int $y,
        public int $button,
        public string $action,
        public bool $ctrl = false,
        public bool $shift = false,
        public bool $alt = false,
    ) {
    }

    public function isLeftClick(): bool
    {
        return self::ACTION_PRESS === $this->action && ($this->button & 3) === self::BUTTON_LEFT;
    }

    public function isRelease(): bool
    {
        return self::ACTION_RELEASE === $this->action;
    }

    public function isWheelUp(): bool
    {
        return self::BUTTON_WHEEL_UP === $this->button;
    }

    public function isWheelDown(): bool
    {
        return self::BUTTON_WHEEL_DOWN === $this->button;
    }

    public function isCtrlClick(): bool
    {
        return $this->isLeftClick() && $this->ctrl;
    }
}

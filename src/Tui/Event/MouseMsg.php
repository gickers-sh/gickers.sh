<?php

declare(strict_types=1);

namespace App\Tui\Event;

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
        public bool $alt = false
    ) {
    }

    public function isLeftClick(): bool
    {
        return $this->action === self::ACTION_PRESS && ($this->button & 3) === self::BUTTON_LEFT;
    }

    public function isRelease(): bool
    {
        return $this->action === self::ACTION_RELEASE;
    }

    public function isWheelUp(): bool
    {
        return $this->button === self::BUTTON_WHEEL_UP;
    }

    public function isWheelDown(): bool
    {
        return $this->button === self::BUTTON_WHEEL_DOWN;
    }

    public function isCtrlClick(): bool
    {
        return $this->isLeftClick() && $this->ctrl;
    }
}

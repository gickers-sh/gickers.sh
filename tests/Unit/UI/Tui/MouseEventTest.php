<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Tui;

use App\UI\Tui\Input\MouseEvent;
use PHPUnit\Framework\TestCase;

final class MouseEventTest extends TestCase
{
    public function testParsesPlainLeftPressWithoutModifiers(): void
    {
        $e = MouseEvent::tryParse("\e[<0;12;5M");
        self::assertNotNull($e);
        self::assertSame(MouseEvent::BUTTON_LEFT, $e->button);
        self::assertSame(12, $e->x);
        self::assertSame(5, $e->y);
        self::assertTrue($e->press);
        self::assertFalse($e->ctrl);
        self::assertFalse($e->alt);
        self::assertFalse($e->shift);
    }

    public function testDecodesCtrlAltShiftModifierBits(): void
    {
        // Ctrl (16) + left button (0) => code 16.
        $ctrl = MouseEvent::tryParse("\e[<16;3;4M");
        self::assertNotNull($ctrl);
        self::assertTrue($ctrl->ctrl);
        self::assertFalse($ctrl->alt);
        self::assertFalse($ctrl->shift);

        // Alt/Meta (8) + left button.
        $alt = MouseEvent::tryParse("\e[<8;3;4M");
        self::assertNotNull($alt);
        self::assertTrue($alt->alt);
        self::assertFalse($alt->ctrl);

        // Shift (4) + left button.
        $shift = MouseEvent::tryParse("\e[<4;3;4M");
        self::assertNotNull($shift);
        self::assertTrue($shift->shift);

        // Ctrl+left combined (16 + 0).
        $combo = MouseEvent::tryParse("\e[<16;3;4M");
        self::assertNotNull($combo);
        self::assertSame(MouseEvent::BUTTON_LEFT, $combo->button);
        self::assertTrue($combo->ctrl);
    }

    public function testDistinguishesButtonsAndReleases(): void
    {
        $middle = MouseEvent::tryParse("\e[<1;3;4M");
        self::assertNotNull($middle);
        self::assertSame(MouseEvent::BUTTON_MIDDLE, $middle->button);

        $right = MouseEvent::tryParse("\e[<2;3;4M");
        self::assertNotNull($right);
        self::assertSame(MouseEvent::BUTTON_RIGHT, $right->button);

        $release = MouseEvent::tryParse("\e[<0;3;4m");
        self::assertNotNull($release);
        self::assertFalse($release->press);
    }

    public function testDecodesWheelUpAndDown(): void
    {
        $up = MouseEvent::tryParse("\e[<64;10;5M");
        self::assertNotNull($up);
        self::assertSame(-1, $up->wheel);
        self::assertSame(10, $up->x);
        self::assertSame(5, $up->y);
        self::assertTrue($up->press);

        $down = MouseEvent::tryParse("\e[<65;10;5M");
        self::assertNotNull($down);
        self::assertSame(1, $down->wheel);

        $click = MouseEvent::tryParse("\e[<0;10;5M");
        self::assertNotNull($click);
        self::assertSame(0, $click->wheel);
    }

    public function testIgnoresMotionAndNonMouseSequences(): void
    {
        self::assertNull(MouseEvent::tryParse("\e[<35;3;4M")); // motion (bit 32) + button
        self::assertTrue(MouseEvent::isReport("\e[<35;3;4M"));
        self::assertNull(MouseEvent::tryParse("\e[A"));        // arrow key
        self::assertFalse(MouseEvent::isReport("\e[A"));
        self::assertNull(MouseEvent::tryParse('j'));           // plain key
    }
}

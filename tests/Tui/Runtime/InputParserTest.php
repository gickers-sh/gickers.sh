<?php

declare(strict_types=1);

namespace App\Tests\Tui\Runtime;

use App\Tui\Event\BackgroundColorMsg;
use App\Tui\Event\KeyMsg;
use App\Tui\Event\MouseMsg;
use App\Tui\Runtime\InputParser;
use PHPUnit\Framework\TestCase;

final class InputParserTest extends TestCase
{
    private InputParser $parser;

    protected function setUp(): void
    {
        $this->parser = new InputParser();
    }

    public function testParseStandardKeys(): void
    {
        $events = $this->parser->parse("q");
        $this->assertCount(1, $events);
        $this->assertInstanceOf(KeyMsg::class, $events[0]);
        $this->assertSame('q', $events[0]->key);

        $events = $this->parser->parse("\n");
        $this->assertSame(KeyMsg::ENTER, $events[0]->key);

        $events = $this->parser->parse("\t");
        $this->assertSame(KeyMsg::TAB, $events[0]->key);

        $events = $this->parser->parse("\x13"); // ctrl+s
        $this->assertSame('ctrl+s', $events[0]->key);
        $this->assertTrue($events[0]->ctrl);
    }

    public function testParseArrowKeys(): void
    {
        $events = $this->parser->parse("\x1b[A\x1b[B\x1b[C\x1b[D");
        $this->assertCount(4, $events);
        $this->assertSame(KeyMsg::UP, $events[0]->key);
        $this->assertSame(KeyMsg::DOWN, $events[1]->key);
        $this->assertSame(KeyMsg::RIGHT, $events[2]->key);
        $this->assertSame(KeyMsg::LEFT, $events[3]->key);
    }

    public function testParseShiftTab(): void
    {
        $sequences = ["\x1b[Z", "\x1b[1;2Z", "\x1b[27;2;9~", "\x1b[9;2u", "\x1b[9;2~"];
        foreach ($sequences as $seq) {
            $events = $this->parser->parse($seq);
            $this->assertCount(1, $events, "Failed parsing Shift+Tab sequence: " . addcslashes($seq, "\x1b"));
            $this->assertSame(KeyMsg::BACKTAB, $events[0]->key);
        }
    }

    public function testParseSgrMouse(): void
    {
        // Left click press at col 15, row 5
        // \x1b[<0;15;5M
        $events = $this->parser->parse("\x1b[<0;15;5M");
        $this->assertCount(1, $events);
        $this->assertInstanceOf(MouseMsg::class, $events[0]);
        $this->assertSame(15, $events[0]->x);
        $this->assertSame(5, $events[0]->y);
        $this->assertTrue($events[0]->isLeftClick());
        $this->assertFalse($events[0]->isRelease());

        // Ctrl+Left click at col 10, row 2
        // button = 0 | 16 (ctrl) = 16
        $events = $this->parser->parse("\x1b[<16;10;2M");
        $this->assertCount(1, $events);
        $this->assertTrue($events[0]->isCtrlClick());

        // Mouse wheel down
        // button = 65
        $events = $this->parser->parse("\x1b[<65;20;10M");
        $this->assertCount(1, $events);
        $this->assertTrue($events[0]->isWheelDown());
    }

    public function testParseOsc11BackgroundColor(): void
    {
        // Dark background: rgb:1a1a/1b1b/2626 (TokyoNight)
        $events = $this->parser->parse("\x1b]11;rgb:1a1a/1b1b/2626\x07");
        $this->assertCount(1, $events);
        $this->assertInstanceOf(BackgroundColorMsg::class, $events[0]);
        $this->assertSame(0x1a, $events[0]->r);
        $this->assertSame(0x1b, $events[0]->g);
        $this->assertSame(0x26, $events[0]->b);
        $this->assertTrue($events[0]->isDark);
    }
}

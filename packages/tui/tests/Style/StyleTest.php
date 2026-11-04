<?php

declare(strict_types=1);

namespace CodesSh\Tui\Tests\Style;

use CodesSh\Tui\Style\Ansi;
use CodesSh\Tui\Style\Border;
use CodesSh\Tui\Style\HorizontalAlign;
use CodesSh\Tui\Style\Layout;
use CodesSh\Tui\Style\Style;
use CodesSh\Tui\Style\VerticalAlign;
use PHPUnit\Framework\TestCase;

final class StyleTest extends TestCase
{
    public function testStylePaddingAndWidth(): void
    {
        $style = Style::create()
            ->width(20)
            ->padding(1, 2)
            ->align(HorizontalAlign::Left);

        $out = $style->render('Hello');
        $lines = explode("\n", $out);

        // 1 top padding + 1 content + 1 bottom padding = 3 lines
        $this->assertCount(3, $lines);
        $this->assertSame(20, Ansi::width($lines[0]));
        $this->assertSame(20, Ansi::width($lines[1]));
        $this->assertSame(20, Ansi::width($lines[2]));
        $this->assertStringContainsString('Hello', $lines[1]);
    }

    public function testStyleBorder(): void
    {
        $style = Style::create()
            ->border(Border::rounded())
            ->borderColor('#7aa2f7');

        $out = $style->render('Box');
        $lines = explode("\n", $out);

        $this->assertCount(3, $lines);
        $this->assertStringContainsString('╭', $lines[0]);
        $this->assertStringContainsString('╮', $lines[0]);
        $this->assertStringContainsString('│', $lines[1]);
        $this->assertStringContainsString('╰', $lines[2]);
        $this->assertStringContainsString('╯', $lines[2]);
    }

    public function testLayoutJoinHorizontal(): void
    {
        $blockA = "Line A1\nLine A2";
        $blockB = "Line B1\nLine B2";
        $joined = Layout::joinHorizontal(VerticalAlign::Top, $blockA, " | \n | ", $blockB);
        $lines = explode("\n", $joined);

        $this->assertCount(2, $lines);
        $this->assertSame('Line A1 | Line B1', Ansi::strip($lines[0]));
        $this->assertSame('Line A2 | Line B2', Ansi::strip($lines[1]));
    }
}

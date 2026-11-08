<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Tui\Widget;

use App\UI\Tui\Widget\ListWidget;
use App\UI\Tui\Widget\ScrollWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class ListAndScrollWidgetTest extends TestCase
{
    public function testListNavigatesWithVimKeysAndSelectsLastItem(): void
    {
        $list = new ListWidget(static fn (mixed $item, bool $selected, int $cols): array => [($selected ? '>' : ' ').(\is_string($item) ? $item : '')]);
        $list->setItems(['a', 'b', 'c']);
        $selected = null;
        $list->onSelect(static function (mixed $item, int $index) use (&$selected): void {
            $selected = [$item, $index];
        });
        $tui = new Tui(terminal: new VirtualTerminal(20, 5));
        $tui->add($list);
        $tui->setFocus($list);

        $tui->handleInput('j');
        $tui->handleInput('j');
        $tui->handleInput('k');
        self::assertSame(1, $list->index());
        $tui->handleInput("\e[F");
        $tui->handleInput("\r");

        self::assertSame(['c', 2], $selected);
    }

    public function testListWindowScrollsMinimallyAndMapsClicksToMultiLineRows(): void
    {
        $list = new ListWidget(static fn (mixed $item, bool $selected, int $cols): array => [\is_int($item) ? 'item '.$item : '', 'meta']);
        $list->setItems(range(0, 9));
        $vt = new VirtualTerminal(20, 4);
        $tui = new Tui(terminal: $vt);
        $tui->add($list);
        $tui->setFocus($list);
        $list->setIndex(3);
        $tui->start();
        $tui->tick();
        $out = $vt->consumeOutput();
        self::assertStringContainsString('item 3', $out);
        self::assertStringContainsString('item 2', $out);
        self::assertStringNotContainsString('item 1', $out);

        $clicked = null;
        $list->onSelect(static function (mixed $item) use (&$clicked): void {
            $clicked = $item;
        });
        self::assertTrue($list->click(1, 0, false)); // second row of item 2: selects only
        self::assertSame(2, $list->index());
        self::assertNull($clicked);
        self::assertTrue($list->click(1, 0, false)); // clicking the selected row opens it
        self::assertSame(2, $clicked);
        $tui->stop();
    }

    public function testScrollJumpsToBottomAndHalfPagesUp(): void
    {
        $scroll = new ScrollWidget();
        $scroll->setLines(array_map(static fn (int $i): string => 'line '.$i, range(1, 50)));
        $vt = new VirtualTerminal(20, 5);
        $tui = new Tui(terminal: $vt);
        $tui->add($scroll);
        $tui->setFocus($scroll);
        $tui->start();
        $tui->tick();

        $tui->handleInput('G');
        self::assertSame(45, $scroll->scroll());
        $tui->handleInput('u');
        self::assertSame(42, $scroll->scroll());
        $tui->handleInput('g');
        self::assertSame(0, $scroll->scroll());
        $tui->stop();
    }
}

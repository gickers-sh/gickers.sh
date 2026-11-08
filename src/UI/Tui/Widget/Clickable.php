<?php

declare(strict_types=1);

namespace App\UI\Tui\Widget;

/**
 * A widget reacting to mouse presses. Coordinates are 0-based and relative to
 * the widget's rendered rect (chrome included).
 *
 * $nav marks the "go to definition" gesture: Ctrl/Alt/Shift+left click, or a
 * middle/right click.
 */
interface Clickable
{
    public function click(int $row, int $col, bool $nav): bool;
}

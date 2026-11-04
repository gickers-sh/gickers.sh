<?php

declare(strict_types=1);

namespace CodesSh\Tui\Style;

final class Border
{
    public static function rounded(): BorderDefinition
    {
        return new BorderDefinition(
            top: '─',
            bottom: '─',
            left: '│',
            right: '│',
            topLeft: '╭',
            topRight: '╮',
            bottomLeft: '╰',
            bottomRight: '╯'
        );
    }

    public static function normal(): BorderDefinition
    {
        return new BorderDefinition(
            top: '─',
            bottom: '─',
            left: '│',
            right: '│',
            topLeft: '┌',
            topRight: '┐',
            bottomLeft: '└',
            bottomRight: '┘'
        );
    }

    public static function thick(): BorderDefinition
    {
        return new BorderDefinition(
            top: '━',
            bottom: '━',
            left: '┃',
            right: '┃',
            topLeft: '┏',
            topRight: '┓',
            bottomLeft: '┗',
            bottomRight: '┛'
        );
    }

    public static function double(): BorderDefinition
    {
        return new BorderDefinition(
            top: '═',
            bottom: '═',
            left: '║',
            right: '║',
            topLeft: '╔',
            topRight: '╗',
            bottomLeft: '╚',
            bottomRight: '╝'
        );
    }

    public static function hidden(): BorderDefinition
    {
        return new BorderDefinition(
            top: ' ',
            bottom: ' ',
            left: ' ',
            right: ' ',
            topLeft: ' ',
            topRight: ' ',
            bottomLeft: ' ',
            bottomRight: ' '
        );
    }
}

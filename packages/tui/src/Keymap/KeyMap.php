<?php

declare(strict_types=1);

namespace CodesSh\Tui\Keymap;

use CodesSh\Tui\Event\KeyMsg;

/**
 * KeyMap defines the keyboard bindings for the codes.sh TUI matching codes.sh.src/pkg/ui/keymap/keymap.go.
 */
final class KeyMap
{
    public KeyBinding $quit;
    public KeyBinding $up;
    public KeyBinding $down;
    public KeyBinding $upDown;
    public KeyBinding $leftRight;
    public KeyBinding $arrows;
    public KeyBinding $gotoTop;
    public KeyBinding $gotoBottom;
    public KeyBinding $select;
    public KeyBinding $section;
    public KeyBinding $back;
    public KeyBinding $prevPage;
    public KeyBinding $nextPage;
    public KeyBinding $help;
    public KeyBinding $selectItem;
    public KeyBinding $backItem;
    public KeyBinding $create;
    public KeyBinding $fork;
    public KeyBinding $settings;
    public KeyBinding $copy;

    public function __construct()
    {
        $this->quit = new KeyBinding(['q', 'ctrl+c'], 'q', 'quit');
        $this->up = new KeyBinding([KeyMsg::UP, 'k'], '↑', 'up');
        $this->down = new KeyBinding([KeyMsg::DOWN, 'j'], '↓', 'down');
        $this->upDown = new KeyBinding([KeyMsg::UP, KeyMsg::DOWN, 'k', 'j'], '↑↓', 'navigate');
        $this->leftRight = new KeyBinding([KeyMsg::LEFT, 'h', KeyMsg::RIGHT, 'l'], '←→', 'navigate');
        $this->arrows = new KeyBinding([KeyMsg::UP, KeyMsg::RIGHT, KeyMsg::DOWN, KeyMsg::LEFT, 'k', 'l', 'j', 'h'], '↑←↓→', 'navigate');
        $this->gotoTop = new KeyBinding([KeyMsg::HOME, 'g'], 'g/home', 'goto top');
        $this->gotoBottom = new KeyBinding([KeyMsg::END, 'G'], 'G/end', 'goto bottom');
        $this->select = new KeyBinding([KeyMsg::ENTER], 'enter', 'select');
        $this->section = new KeyBinding([KeyMsg::TAB, KeyMsg::BACKTAB], 'tab', 'section');
        $this->back = new KeyBinding([KeyMsg::ESC], 'esc', 'back');
        $this->prevPage = new KeyBinding([KeyMsg::PAGE_UP, 'b', 'u'], 'pgup', 'prev page');
        $this->nextPage = new KeyBinding([KeyMsg::PAGE_DOWN, 'f', 'd'], 'pgdn', 'next page');
        $this->help = new KeyBinding(['?'], '?', 'toggle help');
        $this->selectItem = new KeyBinding(['l', KeyMsg::RIGHT], '→/l', 'select');
        $this->backItem = new KeyBinding(['h', KeyMsg::LEFT, KeyMsg::BACKSPACE], '←/h', 'back');
        $this->copy = new KeyBinding(['c', 'ctrl+c'], 'c', 'copy text');
        $this->create = new KeyBinding(['n'], 'n', 'new repository');
        $this->fork = new KeyBinding(['F'], 'F', 'fork repository');
        $this->settings = new KeyBinding(['S'], 'S', 'server settings');
    }

    public static function default(): self
    {
        return new self();
    }

    public static function matches(KeyMsg $msg, KeyBinding $binding): bool
    {
        return $binding->matches($msg);
    }
}

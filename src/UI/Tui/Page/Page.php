<?php

declare(strict_types=1);

namespace App\UI\Tui\Page;

use App\UI\Tui\TuiHost;

/**
 * A full-screen page of the TUI. Pages own a small widget tree (header/tab bar/
 * body/footer) added to the host's {@see \Symfony\Component\Tui\Tui} root and
 * drive all input themselves (keyboard-only) through {@see handleKey()}.
 */
interface Page
{
    /**
     * Build the widget tree, add it to the host's root, and load initial data.
     */
    public function mount(TuiHost $host): void;

    /**
     * Handle one raw key sequence. Returns true when consumed (the host then
     * stops event propagation, re-syncs the page and requests a render).
     */
    public function handleKey(string $seq): bool;

    /**
     * Handle a left-button mouse press at the 1-based terminal cell ($x, $y).
     * Returns true when consumed (the host then re-syncs and requests a render).
     */
    public function handleClick(int $x, int $y, bool $nav = false): bool;

    /**
     * Recompute every dynamic widget (header/tab bar/footer text and body) from
     * the current page state.
     */
    public function sync(): void;

    /**
     * Advance any in-flight asynchronous loading or spinner animations.
     * Returns true while active work is still in progress (keeping fast ticking active),
     * or false when idle.
     */
    public function tick(?float $deltaTime = null): bool;
}

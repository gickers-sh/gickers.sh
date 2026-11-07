<?php

declare(strict_types=1);

namespace App\UI\Tui\Page\Repo;

/**
 * One tab of the {@see \App\UI\Tui\Page\RepoPage}. A tab owns its own selection
 * / scroll state and any overlays it opens (file viewer, diff, merge confirm…)
 * and drives all keyboard input and body painting while it is the active tab.
 */
interface RepoTab
{
    /**
     * Handle one raw key sequence while this tab is active. Returns true when
     * the key was consumed.
     */
    public function handleKey(string $seq): bool;

    /**
     * Handle a left-button mouse press inside this tab's body while it is active.
     * $line is the 0-based row within the painted body (as returned by
     * {@see paintBody()}, i.e. already windowed/scrolled); $col the 0-based inner
     * column. Returns true when consumed.
     */
    public function onClick(int $line, int $col, bool $nav = false): bool;

    /**
     * @return list<string>
     */
    public function paintBody(int $cols, int $rows): array;

    /**
     * Footer hint for the current tab/overlay state.
     */
    public function footerText(): string;

    /**
     * Stretched value cell shown in the status bar for this tab.
     */
    public function statusValue(): string;

    /**
     * Right-aligned info segment (scroll percent or item count) for the status bar.
     */
    public function statusInfo(): string;

    /**
     * True while an overlay is open and must capture every key, bypassing the
     * page-level tab switching / navigation shortcuts.
     */
    public function capturesInput(): bool;

    /**
     * Reset transient state when this tab becomes active.
     */
    public function onActivate(): void;

    /**
     * Advance any tab-local asynchronous loading (file blob, commit diff, PR diff).
     * Returns true while work is still in progress.
     */
    public function tick(?float $deltaTime = null): bool;

    /**
     * Optional replacement tab bar lines (e.g., PR detail sub-tabs: About | Commits | Changes | Comments).
     *
     * @return list<string>|null
     */
    public function customTabBarLines(int $cols): ?array;

    /**
     * True when the tab renders a full-screen modal that hides the repository header and tab bar.
     */
    public function isFullScreenModal(): bool;

    /**
     * Optional status bar branch segment override (e.g. `head → base` in PR detail views).
     */
    public function statusBranch(): ?string;
}

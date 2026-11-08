<?php

declare(strict_types=1);

namespace App\UI\Tui\View\Repo;

use App\UI\Tui\View\View;

/**
 * Base of every repository view: the tab panes shown inside {@see RepoView}
 * and the views pushed above it (file viewer, diff, forms). Supplies the
 * status-bar segments painted by {@see RepoViews::frame()} and clears the
 * repository flash on every key while it is the top view (old `RepoPage`).
 */
abstract class RepoScreen extends View
{
    public function __construct(protected readonly RepoViews $repo)
    {
        $this->expandVertically(true);
    }

    /** Status bar middle segment (old `RepoTab::statusValue()`). */
    public function statusValue(): string
    {
        return '';
    }

    /** Status bar info segment (old `RepoTab::statusInfo()`). */
    public function statusInfo(): string
    {
        return '';
    }

    /** Status bar branch segment override; null shows `* <ref>`. */
    public function statusBranch(): ?string
    {
        return null;
    }

    public function onAnyKey(string $data): bool
    {
        $this->repo->flash = null;

        return false;
    }
}

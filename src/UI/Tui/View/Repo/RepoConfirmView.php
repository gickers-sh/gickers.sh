<?php

declare(strict_types=1);

namespace App\UI\Tui\View\Repo;

use App\UI\Tui\Render\Theme;
use App\UI\Tui\View\Common\ConfirmView;
use App\UI\Tui\View\View;

/**
 * A yes/no box drawn inside the repository frame (header, tab bar and status
 * stay visible), as the old in-tab confirmations (merge, delete) were.
 * Keys come from the wrapped {@see ConfirmView}: y/Enter pops then runs
 * $onYes, n/Esc/q pops.
 */
final class RepoConfirmView extends RepoScreen
{
    private readonly ConfirmView $confirm;

    /**
     * @param list<string>     $body
     * @param \Closure(): void $onYes
     */
    public function __construct(
        RepoViews $repo,
        private readonly RepoScreen $status,
        int $tab,
        string $title,
        array $body,
        \Closure $onYes,
        string $hint = 'y to confirm • n/esc to cancel',
        ?string $borderColor = Theme::BLUE,
        bool $fullScreen = false,
    ) {
        parent::__construct($repo);
        $this->confirm = new ConfirmView($repo->navigator(), $title, $body, $onYes, $hint, $borderColor);
        $this->add($repo->frame($this, $this->confirm, $tab, $fullScreen));
    }

    public function title(): string
    {
        return $this->confirm->title();
    }

    public function activeChild(): View
    {
        return $this->confirm;
    }

    public function keyHints(): array
    {
        return $this->confirm->keyHints();
    }

    public function statusValue(): string
    {
        return $this->status->statusValue();
    }

    public function statusInfo(): string
    {
        return $this->status->statusInfo();
    }

    public function statusBranch(): ?string
    {
        return $this->status->statusBranch();
    }
}

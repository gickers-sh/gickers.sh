<?php

declare(strict_types=1);

namespace App\UI\Tui\View\Repo;

use App\UI\Tui\View\Repo\Commits\CommitsPane;
use App\UI\Tui\View\Repo\Deployments\DeploymentsPane;
use App\UI\Tui\View\Repo\Files\FilesPane;
use App\UI\Tui\View\Repo\Profile\ProfilePane;
use App\UI\Tui\View\Repo\Readme\ReadmePane;
use App\UI\Tui\View\Repo\Refs\RefsPane;
use App\UI\Tui\View\Repo\Settings\SettingsPane;
use App\UI\Tui\View\View;
use App\UI\Tui\Widget\ChromeWidget;
use Symfony\Component\Tui\Input\Key;

final class RepoView extends RepoScreen
{
    private int $activeTab = 0;
    /** @var list<RepoScreen> */
    private readonly array $panes;
    private ChromeWidget $chrome;

    public function __construct(RepoViews $repo)
    {
        parent::__construct($repo);
        $repo->bindRoot($this);

        $this->panes = [
            new ReadmePane($this->repo),
            new FilesPane($this->repo),
            new CommitsPane($this->repo),
            new RefsPane($this->repo, true),
            new RefsPane($this->repo, false),
            new DeploymentsPane($this->repo),
            new ProfilePane($this->repo),
            new SettingsPane($this->repo),
        ];

        $this->chrome = $this->buildFrame($this->activeTab);
        $this->add($this->chrome);
    }

    public function title(): string
    {
        return $this->repo->state->name;
    }

    public function findChrome(): ChromeWidget
    {
        return $this->chrome;
    }

    public function activeTab(): int
    {
        return $this->activeTab;
    }

    public function activeChild(): View
    {
        return $this->panes[$this->activeTab];
    }

    public function shortcuts(): array
    {
        return [
            'next_tab' => [Key::TAB],
            'prev_tab' => ['shift+tab'],
            'back' => ['q', Key::ESCAPE],
            'tab_1' => ['1'],
            'tab_2' => ['2'],
            'tab_3' => ['3'],
            'tab_4' => ['4'],
            'tab_5' => ['5'],
            'tab_6' => ['6'],
            'tab_7' => ['7'],
            'tab_8' => ['8'],
        ];
    }

    public function handleShortcut(string $action): bool
    {
        return match ($action) {
            'next_tab' => $this->cycleTab(1),
            'prev_tab' => $this->cycleTab(-1),
            'back' => $this->back(),
            'tab_1' => $this->jumpTab(0),
            'tab_2' => $this->jumpTab(1),
            'tab_3' => $this->jumpTab(2),
            'tab_4' => $this->jumpTab(3),
            'tab_5' => $this->jumpTab(4),
            'tab_6' => $this->jumpTab(5),
            'tab_7' => $this->jumpTab(6),
            'tab_8' => $this->jumpTab(7),
            default => false,
        };
    }

    public function selectTab(int $index): void
    {
        if ($index < 0 || $index >= \count($this->panes) || $index === $this->activeTab) {
            return;
        }

        $this->activeTab = $index;
        $pane = $this->panes[$index];

        if (method_exists($pane, 'resetNavigation')) {
            $pane->resetNavigation();
        }

        $this->clear();
        $this->chrome = $this->buildFrame($index);
        $this->add($this->chrome);

        $pane->activate();
        $this->chrome->refresh();
    }

    public function refreshChrome(): void
    {
        $this->chrome->refresh();
    }

    public function helpColumns(): array
    {
        $columns = [
            [['esc', 'back to menu'], ['tab', 'switch tab'], ['1–8', 'jump to tab']],
            [['↑/k', 'up'], ['↓/j', 'down'], ['g/G', 'top / bottom']],
            [['enter', 'open'], ['f/b', 'page down / up'], ['?', 'toggle help']],
        ];

        $actions = match ($this->activeTab) {
            1, 2, 3, 4 => [['/', 'fuzzy search']],
            5 => [['/', 'fuzzy search'], ['r', 'refresh']],
            default => [],
        };

        if ([] !== $actions) {
            $columns[] = $actions;
        }

        return $columns;
    }

    public function statusValue(): string
    {
        return $this->panes[$this->activeTab]->statusValue();
    }

    public function statusInfo(): string
    {
        return $this->panes[$this->activeTab]->statusInfo();
    }

    public function statusBranch(): ?string
    {
        return $this->panes[$this->activeTab]->statusBranch();
    }

    public function deactivate(): void
    {
        foreach ($this->panes as $pane) {
            $this->repo->async()->cancel($pane);
            $pane->deactivate();
        }
        $this->repo->cancel();
    }

    protected function onActivate(): void
    {
        $this->panes[$this->activeTab]->activate();
    }

    private function buildFrame(int $tab): ChromeWidget
    {
        $pane = $this->panes[$tab];

        return $this->repo->frame($this, $pane, $tab);
    }

    private function cycleTab(int $dir): bool
    {
        $count = \count($this->panes);
        $target = ($this->activeTab + $dir) % $count;
        if ($target < 0) {
            $target += $count;
        }

        $this->selectTab($target);

        return true;
    }

    private function jumpTab(int $tab): bool
    {
        $this->selectTab($tab);

        return true;
    }

    private function back(): bool
    {
        $this->repo->views->back();

        return true;
    }
}

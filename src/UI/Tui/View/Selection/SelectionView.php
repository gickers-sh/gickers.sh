<?php

declare(strict_types=1);

namespace App\UI\Tui\View\Selection;

use App\UI\Tui\Format\Chrome;
use App\UI\Tui\View\View;
use App\UI\Tui\Views;
use App\UI\Tui\Widget\ChromeWidget;
use App\UI\Tui\Widget\TabBarWidget;
use Symfony\Component\Tui\Input\Key;

final class SelectionView extends View
{
    public const TABS = ['About', 'Feed', 'My Repositories', 'Profile'];

    private int $activeTab = 0;
    private readonly TabBarWidget $tabBar;
    private ChromeWidget $chrome;
    /** @var list<View> */
    private readonly array $panes;
    private readonly RepoListPane $feedPane;
    private readonly RepoListPane $ownedPane;
    private ?string $flash = null;

    public function __construct(private readonly Views $views)
    {
        $this->expandVertically(true);

        $this->tabBar = new TabBarWidget(self::TABS, TabBarWidget::DOTS, 1);
        $this->tabBar->setActive(0);
        $this->tabBar->onSelect(fn (int $i) => $this->selectTab($i));

        $onTab = function (int $dir): void {
            $this->cycleTab($dir);
        };
        $onFlash = function (string $msg): void {
            $this->flash = $msg;
            $this->chrome->refresh();
        };

        $this->feedPane = new RepoListPane($this->views, false, $onTab, $onFlash);
        $this->ownedPane = new RepoListPane($this->views, true, $onTab, $onFlash);
        $this->panes = [
            new AboutPane($this->views),
            $this->feedPane,
            $this->ownedPane,
            new ProfilePane($this->views->session()->actor),
        ];

        $this->chrome = new ChromeWidget($this->tabBar, $this->panes[0]);
        $this->chrome->setHeader(fn (int $cols): array => [
            ' '.Chrome::serverBadge('' !== $this->views->settings->name ? $this->views->settings->name : 'Soft Serve'),
            '',
        ]);

        $this->chrome->setStatus(function (int $cols): array {
            if (null !== $this->flash) {
                return [Chrome::helpLine([[trim($this->flash), '']], $cols)];
            }

            return [Chrome::helpLine($this->panes[$this->activeTab]->keyHints(), $cols)];
        });

        $this->add($this->chrome);
    }

    public function title(): string
    {
        return 'Soft Serve';
    }

    public function activeChild(): ?View
    {
        return $this->panes[$this->activeTab] ?? null;
    }

    public function shortcuts(): array
    {
        return [
            'next_tab' => [Key::TAB],
            'prev_tab' => ['shift+tab'],
            'back' => ['q', Key::ESCAPE],
        ];
    }

    public function handleShortcut(string $action): bool
    {
        return match ($action) {
            'next_tab' => $this->cycleTab(1),
            'prev_tab' => $this->cycleTab(-1),
            'back' => $this->back(),
            default => false,
        };
    }

    public function onAnyKey(string $data): bool
    {
        if (null !== $this->flash) {
            $this->flash = null;
            $this->chrome->refresh();

            return true;
        }

        return false;
    }

    public function helpColumns(): array
    {
        return [
            [['tab', 'section'], ['/', 'search']],
            [['→/l', 'select']],
            [['↑/k', 'up'], ['↓/j', 'down']],
            [['g/home', 'go to start'], ['G/end', 'go to end']],
            [['?', 'toggle help'], ['q', 'quit']],
        ];
    }

    public function keyHints(): array
    {
        return $this->panes[$this->activeTab]->keyHints();
    }

    public function selectTab(int $index): void
    {
        if ($index < 0 || $index >= \count($this->panes) || $index === $this->activeTab) {
            return;
        }

        $currentPane = $this->panes[$this->activeTab];
        if ($currentPane instanceof RepoListPane) {
            $currentPane->leave();
        }

        $this->activeTab = $index;
        $this->tabBar->setActive($index);

        $newPane = $this->panes[$index];
        $this->chrome->setBody($newPane);
        $newPane->activate();
        $this->chrome->refresh();
    }

    public function deactivate(): void
    {
        foreach ($this->panes as $pane) {
            $this->views->async()->cancel($pane);
            $pane->deactivate();
        }
    }

    public function beforeRender(): void
    {
        if (null !== $this->views->session()->flash) {
            $this->flash = $this->views->session()->flash;
            $this->views->session()->flash = null;
            $this->chrome->refresh();
        }
    }

    protected function onActivate(): void
    {
        if (null !== $this->views->session()->flash) {
            $this->flash = $this->views->session()->flash;
            $this->views->session()->flash = null;
        }

        $this->panes[$this->activeTab]->activate();
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

    private function back(): bool
    {
        $this->views->back();

        return true;
    }
}

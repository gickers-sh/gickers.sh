<?php

declare(strict_types=1);

namespace App\UI\Tui\View\Selection;

use App\Repo\Domain\Model\Repo;
use App\UI\Tui\Format\Fuzzy;
use App\UI\Tui\Format\Rows;
use App\UI\Tui\Format\Time;
use App\UI\Tui\View\View;
use App\UI\Tui\Views;
use App\UI\Tui\Widget\FilterableListWidget;
use App\UI\Tui\Widget\ListWidget;
use Symfony\Component\Tui\Input\Key;

final class RepoListPane extends View
{
    private readonly FilterableListWidget $filterableList;
    private readonly ListWidget $list;
    private bool $loaded = false;
    private int $savedIndex = 0;

    /**
     * @param \Closure(int $dir): void $onTab
     * @param \Closure(string): void   $onFlash
     */
    public function __construct(
        private readonly Views $views,
        private readonly bool $ownedOnly,
        private readonly \Closure $onTab,
        private readonly \Closure $onFlash,
    ) {
        $this->expandVertically(true);

        $list = new ListWidget(function (mixed $item, bool $selected, int $cols) use (&$list): array {
            if (!$item instanceof Repo || null === $list) {
                return [];
            }

            $first = $list->items()[0] ?? null;
            $prefix = ($item !== $first) ? [''] : [];

            $name = $item->nameString();
            $desc = trim($item->description());
            $clone = $this->cloneCommand($name);
            $updated = 'Updated ' . Time::ago($item->updatedAt());

            $rows = Rows::repo($name, $desc, $clone, $updated, $selected, $cols);

            return array_merge($prefix, $rows);
        });

        $this->list = $list;
        $this->updateEmptyText(false);

        $this->filterableList = new FilterableListWidget(
            $this->list,
            fn(string $query, array $all): array => $this->filterRepos($query, $all)
        );

        $this->filterableList->onKey(function (string $data): bool {
            if ("\t" === $data) {
                ($this->onTab)(1);

                return true;
            }
            if ("\x1b[Z" === $data) { // shift+tab
                ($this->onTab)(-1);

                return true;
            }

            return false;
        });

        $this->filterableList->onToggle(function (bool $open): void {
            $this->updateEmptyText($open);
            if (!$open) {
                $this->list->setIndex($this->savedIndex);
            }
        });

        $this->list->onSelect(function (mixed $item): void {
            if ($item instanceof Repo) {
                if ($this->filterableList->isOpen()) {
                    $this->filterableList->close();
                }
                $this->views->openRepo($item->nameString());
                if (null !== $this->views->session()->flash) {
                    ($this->onFlash)($this->views->session()->flash);
                }
            }
        });

        $this->add($this->filterableList);
    }

    public function title(): string
    {
        return $this->ownedOnly ? 'My Repositories' : 'Feed';
    }

    public function shortcuts(): array
    {
        return [
            'page_up' => ['ctrl+u', 'ctrl+b', 'b'],
            'page_down' => ['ctrl+d', 'ctrl+f', Key::SPACE, 'f'],
            'open' => [Key::RIGHT, 'l'],
            'filter' => ['/', 'ctrl+p'],
            'reload' => ['r'],
        ];
    }

    public function handleShortcut(string $action): bool
    {
        return match ($action) {
            'page_up' => $this->list->apply('page_up'),
            'page_down' => $this->list->apply('page_down'),
            'open' => $this->list->apply('select'),
            'filter' => $this->openFilter(),
            'reload' => $this->refresh(),
            default => false,
        };
    }

    public function keyHints(): array
    {
        if ($this->filterableList->isOpen()) {
            return [
                ['type', 'filter'],
                ['↑/↓', 'move'],
                ['enter', 'open'],
                ['esc', 'close'],
            ];
        }

        return [
            ['/', 'search'],
            ['↑↓', 'navigate'],
            ['tab', 'section'],
            ['→/l', 'select'],
            ['r', 'refresh'],
            ['q', 'quit'],
            ['?', 'toggle help'],
        ];
    }

    public function refresh(): bool
    {
        $this->reload();
        ($this->onFlash)('Refreshed');

        return true;
    }

    public function reload(): void
    {
        $actor = $this->views->session()->actor;
        $repos = $this->ownedOnly
            ? (null !== $actor ? $this->views->repoQuery->ownedBy($actor) : [])
            : $this->views->repoQuery->visibleTo($actor);

        $this->filterableList->setAll($repos);
        $this->loaded = true;
    }

    public function selectName(string $name): void
    {
        foreach ($this->list->items() as $i => $item) {
            if ($item instanceof Repo && $item->nameString() === $name) {
                $this->list->setIndex($i);
                $this->savedIndex = $i;
                break;
            }
        }
    }

    public function leave(): void
    {
        if ($this->filterableList->isOpen()) {
            $this->filterableList->close();
        }
    }

    protected function onActivate(): void
    {
        if (!$this->loaded) {
            $this->reload();
        }
        $this->focus($this->filterableList->isOpen() ? $this->filterableList->input() : $this->list);
    }

    private function openFilter(): bool
    {
        $this->savedIndex = $this->list->index();
        $this->filterableList->open();

        return true;
    }

    private function cloneCommand(string $name): string
    {
        $url = $this->views->settings->sshPublicUrl;
        if ('' === $url) {
            return '';
        }

        return 'git clone ' . rtrim($url, '/') . '/' . $name . '.git';
    }

    private function updateEmptyText(bool $filterOpen): void
    {
        if ($filterOpen) {
            $this->list->setEmptyText("\n  No matching repositories.");

            return;
        }

        if (!$this->ownedOnly) {
            $this->list->setEmptyText("\n  No recent activity.");

            return;
        }

        if (null === $this->views->session()->actor) {
            $this->list->setEmptyText("\n  Sign in over SSH to see your repositories.");
        } else {
            $this->list->setEmptyText("\n  No repositories found.");
        }
    }

    /**
     * @param list<mixed> $all
     *
     * @return list<mixed>
     */
    private function filterRepos(string $query, array $all): array
    {
        $q = trim($query);
        if ('' === $q) {
            return $all;
        }

        $scored = [];
        foreach ($all as $idx => $item) {
            if (!$item instanceof Repo) {
                continue;
            }
            $score = Fuzzy::fields($q, [
                ['text' => $item->nameString(), 'weight' => 3],
                ['text' => $item->projectName(), 'weight' => 3],
                ['text' => $item->owner()->username(), 'weight' => 2],
                ['text' => $item->description(), 'weight' => 2],
                ['text' => $this->cloneCommand($item->nameString()), 'weight' => 1],
            ]);

            if (null !== $score) {
                $scored[] = ['score' => $score, 'index' => $idx, 'item' => $item];
            }
        }

        usort($scored, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return $a['index'] <=> $b['index'];
        });

        return array_map(static fn(array $entry): mixed => $entry['item'], $scored);
    }
}

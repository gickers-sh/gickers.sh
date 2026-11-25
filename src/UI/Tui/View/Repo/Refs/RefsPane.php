<?php

declare(strict_types=1);

namespace App\UI\Tui\View\Repo\Refs;

use App\UI\Tui\Format\Fuzzy;
use App\UI\Tui\Format\Rows;
use App\UI\Tui\Format\Time;
use App\UI\Tui\View\Repo\RepoScreen;
use App\UI\Tui\View\Repo\RepoViews;
use App\UI\Tui\Widget\FilterableListWidget;
use App\UI\Tui\Widget\ListWidget;
use Symfony\Component\Tui\Widget\LoaderWidget;

final class RefsPane extends RepoScreen
{
    private readonly FilterableListWidget $filterableList;
    private readonly ListWidget $list;
    private readonly LoaderWidget $loader;
    private bool $loaded = false;

    public function __construct(
        RepoViews $repo,
        private readonly bool $branch,
    ) {
        parent::__construct($repo);

        $this->loader = new LoaderWidget($this->branch ? 'Loading branches...' : 'Loading tags...');
        $this->loader->addStyleClass('indent');
        $this->add($this->loader);

        $this->list = new ListWidget(function (mixed $item, bool $selected, int $cols): array {
            if (!\is_string($item)) {
                return [];
            }

            $meta = $this->repo->data->refMeta($item);
            $updated = null !== $meta ? Time::relative($meta['time']) : '';
            $hash = null !== $meta ? substr($meta['hash'], 0, 7) : '';

            return Rows::refRows($item, $updated, $hash, $selected, $cols);
        });

        $this->updateEmptyText(false);

        $this->filterableList = new FilterableListWidget(
            $this->list,
            fn (string $query, array $all): array => $this->filterRefs($query, $all)
        );

        $this->filterableList->onToggle(function (bool $open): void {
            $this->updateEmptyText($open);
        });

        $this->list->onSelect(function (mixed $item): void {
            if (\is_string($item)) {
                if ($this->filterableList->isOpen()) {
                    $this->filterableList->close();
                }
                $this->repo->state->switchRef($item);
                $this->repo->flash = 'Switched to '.$item;
                $this->repo->root()->refreshChrome();
            }
        });

        $this->add($this->filterableList);
        $this->filterableList->addStyleClass('hidden');
    }

    public function title(): string
    {
        return $this->branch ? 'Branches' : 'Tags';
    }

    public function shortcuts(): array
    {
        return [
            'filter' => ['/', 'ctrl+p'],
        ];
    }

    public function handleShortcut(string $action): bool
    {
        if ('filter' === $action) {
            $this->filterableList->open();

            return true;
        }

        return false;
    }

    public function keyHints(): array
    {
        if ($this->filterableList->isOpen()) {
            return [
                ['type', 'filter'],
                ['↑/↓', 'move'],
                ['enter', 'switch'],
                ['esc', 'close'],
            ];
        }

        return [
            ['/', 'search'],
            ['↑↓', 'navigate'],
            ['tab', 'section'],
            ['enter', 'switch'],
            ['↑/k', 'up'],
            ['↓/j', 'down'],
            ['q', 'quit'],
            ['?', 'toggle help'],
        ];
    }

    public function statusValue(): string
    {
        if ($this->filterableList->isOpen()) {
            $q = $this->filterableList->query();

            return '' !== $q ? '/'.$q : '[SEARCH]';
        }

        if ($this->repo->data->isLoading('refs')) {
            return '';
        }

        $items = $this->list->items();
        if ([] === $items) {
            return '';
        }

        $selected = $this->list->selected();
        $first = $items[0] ?? '';
        $ref = \is_string($selected) ? $selected : (\is_string($first) ? $first : '');

        return ($this->branch ? 'refs/heads/' : 'refs/tags/').$ref;
    }

    public function statusInfo(): string
    {
        if ($this->repo->data->isLoading('refs')) {
            return '…';
        }

        $items = $this->list->items();
        $count = \count($items);
        if (0 === $count) {
            return '';
        }

        return 'p. '.($this->list->index() + 1).'/'.$count;
    }

    public function resetNavigation(): void
    {
        if ($this->filterableList->isOpen()) {
            $this->filterableList->close();
        }
        $this->list->setIndex(0);
    }

    protected function onActivate(): void
    {
        if ($this->loaded) {
            $this->focus($this->filterableList->isOpen() ? $this->filterableList->input() : $this->list);

            return;
        }
        $this->loadRefs();
        $this->focus($this->filterableList->isOpen() ? $this->filterableList->input() : $this->list);
    }

    private function loadRefs(): void
    {
        $this->filterableList->setLoading(true);

        $this->repo->data->refs(function (array $data): void {
            $this->loaded = true;
            $items = $this->branch ? $data['branches'] : $data['tags'];

            $this->loader->addStyleClass('hidden');
            $this->filterableList->removeStyleClass('hidden');
            $this->filterableList->setLoading(false);
            $this->filterableList->setAll($items);
            $this->updateEmptyText($this->filterableList->isOpen());
            $this->repo->root()->refreshChrome();
        });
    }

    private function updateEmptyText(bool $filterOpen): void
    {
        if ($filterOpen) {
            $this->list->setEmptyText($this->branch ? "\n  No matching branches." : "\n  No matching tags.");
        } else {
            $this->list->setEmptyText($this->branch ? "\n  No branches." : "\n  No tags.");
        }
    }

    /**
     * @param list<mixed> $all
     *
     * @return list<mixed>
     */
    private function filterRefs(string $query, array $all): array
    {
        $q = trim($query);
        if ('' === $q) {
            return $all;
        }

        $scored = [];
        foreach ($all as $idx => $ref) {
            if (!\is_string($ref)) {
                continue;
            }

            $meta = $this->repo->data->refMeta($ref);
            $hash = $meta['hash'] ?? '';

            $score = Fuzzy::fields($q, [
                ['text' => $ref, 'weight' => 3],
                ['text' => substr($hash, 0, 7), 'weight' => 2],
                ['text' => $hash, 'weight' => 1],
            ]);

            if (null !== $score) {
                $scored[] = ['ref' => $ref, 'score' => $score, 'idx' => $idx];
            }
        }

        usort($scored, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return $a['idx'] <=> $b['idx'];
        });

        return array_map(static fn (array $item): string => $item['ref'], $scored);
    }
}

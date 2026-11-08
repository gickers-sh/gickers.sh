<?php

declare(strict_types=1);

namespace App\UI\Tui\View\Repo\Commits;

use App\Repo\Domain\Git\Commit;
use App\UI\Tui\Format\Fuzzy;
use App\UI\Tui\Format\Rows;
use App\UI\Tui\View\Repo\RepoScreen;
use App\UI\Tui\View\Repo\RepoViews;
use App\UI\Tui\Widget\FilterableListWidget;
use App\UI\Tui\Widget\ListWidget;
use Symfony\Component\Tui\Widget\LoaderWidget;

final class CommitsPane extends RepoScreen
{
    private readonly FilterableListWidget $filterableList;
    private readonly ListWidget $list;
    private readonly LoaderWidget $loader;
    private bool $loaded = false;

    public function __construct(RepoViews $repo)
    {
        parent::__construct($repo);

        $this->loader = new LoaderWidget('Loading commits...');
        $this->loader->addStyleClass('indent');
        $this->add($this->loader);

        $list = new ListWidget(static function (mixed $item, bool $selected, int $cols) use (&$list): array {
            if (!$item instanceof Commit || null === $list) {
                return [];
            }

            $meta = $item->authorName.' committed on '.date('M j', $item->timestamp);
            $first = $list->items()[0] ?? null;
            $prefix = ($item !== $first) ? [''] : [];

            return array_merge($prefix, Rows::commit($item->subject, $meta, $item->shortHash, $selected, $cols));
        });
        $this->list = $list;
        $this->updateEmptyText(false);

        $this->filterableList = new FilterableListWidget(
            $this->list,
            fn (string $query, array $all): array => $this->filterCommits($query, $all)
        );

        $this->filterableList->onToggle(function (bool $open): void {
            $this->updateEmptyText($open);
        });

        $this->list->onSelect(function (mixed $item): void {
            if ($item instanceof Commit) {
                if ($this->filterableList->isOpen()) {
                    $this->filterableList->close();
                }
                $this->repo->navigator()->push(new CommitDiffView($this->repo, $item->hash, $item->subject, 2));
            }
        });

        $this->add($this->filterableList);
        $this->filterableList->addStyleClass('hidden');
    }

    public function title(): string
    {
        return 'Commits';
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
                ['enter', 'open'],
                ['esc', 'close'],
            ];
        }

        return [
            ['/', 'search'],
            ['↑↓', 'navigate'],
            ['tab', 'section'],
            ['enter', 'open'],
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

        if ($this->repo->data->isLoading('commits')) {
            return '';
        }

        $items = $this->list->items();
        if ([] === $items) {
            return '';
        }

        $selected = $this->list->selected();
        if ($selected instanceof Commit) {
            return substr($selected->hash, 0, 7);
        }

        return '';
    }

    public function statusInfo(): string
    {
        if ($this->repo->data->isLoading('commits')) {
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
        $this->loadCommits();
        $this->focus($this->filterableList->isOpen() ? $this->filterableList->input() : $this->list);
    }

    private function loadCommits(): void
    {
        $this->filterableList->setLoading(true);

        $this->repo->data->commits(function (array $commits): void {
            $this->loaded = true;
            $this->loader->addStyleClass('hidden');
            $this->filterableList->removeStyleClass('hidden');
            $this->filterableList->setLoading(false);
            $this->filterableList->setAll($commits);
            $this->updateEmptyText($this->filterableList->isOpen());
            $this->repo->root()->refreshChrome();
        });
    }

    private function updateEmptyText(bool $filterOpen): void
    {
        if ($filterOpen) {
            $this->list->setEmptyText("\n  No matching commits.");
        } else {
            $this->list->setEmptyText("\n  No commits.");
        }
    }

    /**
     * @param list<mixed> $all
     *
     * @return list<mixed>
     */
    private function filterCommits(string $query, array $all): array
    {
        $q = trim($query);
        if ('' === $q) {
            return $all;
        }

        $scored = [];
        foreach ($all as $idx => $commit) {
            if (!$commit instanceof Commit) {
                continue;
            }

            $score = Fuzzy::fields($q, [
                ['text' => $commit->subject, 'weight' => 3],
                ['text' => $commit->body, 'weight' => 2],
                ['text' => $commit->authorName, 'weight' => 2],
                ['text' => substr($commit->hash, 0, 7), 'weight' => 2],
                ['text' => $commit->hash, 'weight' => 1],
            ]);

            if (null !== $score) {
                $scored[] = ['commit' => $commit, 'score' => $score, 'idx' => $idx];
            }
        }

        usort($scored, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return $a['idx'] <=> $b['idx'];
        });

        return array_map(static fn (array $item): mixed => $item['commit'], $scored);
    }
}

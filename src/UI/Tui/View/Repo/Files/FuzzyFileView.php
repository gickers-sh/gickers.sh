<?php

declare(strict_types=1);

namespace App\UI\Tui\View\Repo\Files;

use App\Repo\Domain\Git\TreeEntry;
use App\UI\Tui\Format\Fuzzy;
use App\UI\Tui\Format\Rows;
use App\UI\Tui\Format\Size;
use App\UI\Tui\View\Repo\RepoScreen;
use App\UI\Tui\View\Repo\RepoViews;
use App\UI\Tui\Widget\FilterableListWidget;
use App\UI\Tui\Widget\ListWidget;
use Symfony\Component\Tui\Widget\LoaderWidget;

final class FuzzyFileView extends RepoScreen
{
    private readonly FilterableListWidget $filterableList;
    private readonly ListWidget $list;
    private readonly LoaderWidget $loader;

    public function __construct(
        RepoViews $repo,
        private readonly FilesPane $filesPane,
    ) {
        parent::__construct($repo);

        $this->loader = new LoaderWidget('Loading files...');
        $this->loader->addStyleClass('indent');

        /** @var FilterableListWidget|null $filterableList */
        $filterableList = null;
        $list = new ListWidget(static function (mixed $item, bool $selected, int $cols) use (&$filterableList): array {
            if (!$item instanceof TreeEntry) {
                return [];
            }

            $mode = FilesPane::modeString($item);
            $size = $item->isDirectory() ? '' : Size::human($item->size ?? 0);
            $path = $item->path;

            $q = null !== $filterableList ? $filterableList->query() : '';
            $match = Fuzzy::path($q, $path);
            $positions = $match['positions'] ?? [];

            $rendered = Rows::highlightPath($path, $positions, $selected);

            return Rows::file($mode, $size, $path, $item->isDirectory(), $selected, $cols, $rendered);
        });
        $this->list = $list;

        $this->filterableList = new FilterableListWidget(
            $this->list,
            fn (string $query, array $all): array => $this->filterFuzzy($query, $all)
        );
        $filterableList = $this->filterableList;

        $this->filterableList->onToggle(function (bool $open): void {
            if (!$open) {
                $this->repo->navigator()->pop();
            }
        });

        $this->list->onSelect(function (mixed $item): void {
            if ($item instanceof TreeEntry) {
                if ($item->isDirectory()) {
                    $this->repo->state->subpath = $item->path;
                    $this->repo->navigator()->pop();
                    $this->filesPane->reload();
                } else {
                    $this->repo->navigator()->replace(new FileView($this->repo, $item->path));
                }
            }
        });

        $body = new \Symfony\Component\Tui\Widget\ContainerWidget();
        $body->expandVertically(true);
        $body->add($this->loader);
        $body->add($this->filterableList);
        $this->filterableList->addStyleClass('hidden');

        $this->add($repo->frame($this, $body, 1));
    }

    public function title(): string
    {
        return 'Find file';
    }

    public function statusValue(): string
    {
        $q = $this->filterableList->query();

        return '' !== $q ? '/'.$q : '[SEARCH]';
    }

    public function statusInfo(): string
    {
        if ($this->repo->data->isLoading('recursiveTree')) {
            return '…';
        }

        $items = $this->list->items();
        $count = \count($items);
        if (0 === $count) {
            return '0/'.\count($this->filterableList->allItems());
        }

        return '# '.($this->list->index() + 1).'/'.$count;
    }

    public function keyHints(): array
    {
        return [
            ['type', 'filter'],
            ['↑/↓', 'move'],
            ['enter', 'open'],
            ['esc', 'close'],
        ];
    }

    protected function onActivate(): void
    {
        $this->loadFiles();
    }

    private function loadFiles(): void
    {
        $this->filterableList->setLoading(true);

        $this->repo->data->recursiveTree(function (array $entries): void {
            $this->loader->addStyleClass('hidden');
            $this->filterableList->removeStyleClass('hidden');
            $this->filterableList->setLoading(false);
            $this->filterableList->setAll($entries);
            $this->filterableList->open();
            $this->focus($this->filterableList->input());
            $this->repo->root()->refreshChrome();
        });
    }

    /**
     * @param list<mixed> $all
     *
     * @return list<mixed>
     */
    private function filterFuzzy(string $query, array $all): array
    {
        $q = trim($query);
        if ('' === $q) {
            return $all;
        }

        $scored = [];
        foreach ($all as $idx => $entry) {
            if (!$entry instanceof TreeEntry) {
                continue;
            }
            $match = Fuzzy::path($q, $entry->path);
            if (null !== $match) {
                $scored[] = [
                    'entry' => $entry,
                    'score' => $match['score'],
                    'len' => strlen($entry->path),
                    'idx' => $idx,
                ];
            }
        }

        usort($scored, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            if ($a['len'] !== $b['len']) {
                return $a['len'] <=> $b['len'];
            }

            return $a['idx'] <=> $b['idx'];
        });

        return array_map(static fn (array $item): mixed => $item['entry'], $scored);
    }
}

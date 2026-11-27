<?php

declare(strict_types=1);

namespace App\UI\Tui\View\Selection;

use App\UI\Tui\Format\Fuzzy;
use App\UI\Tui\Format\Rows;
use App\UI\Tui\Format\Time;
use App\UI\Tui\View\View;
use App\UI\Tui\Views;
use App\UI\Tui\Widget\FilterableListWidget;
use App\UI\Tui\Widget\ListWidget;
use App\Wiki\Domain\Model\WikiPage;
use Symfony\Component\Tui\Input\Key;

final class WikiPane extends View
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
        private readonly \Closure $onTab,
        private readonly \Closure $onFlash,
    ) {
        $this->expandVertically(true);

        $list = new ListWidget(function (mixed $item, bool $selected, int $cols) use (&$list): array {
            if (!$item instanceof WikiPage || null === $list) {
                return [];
            }

            $first = $list->items()[0] ?? null;
            $prefix = ($item !== $first) ? [''] : [];

            $title = $item->title();
            $cat = $item->category();
            $slug = $item->slugString();
            $summary = self::extractSummary($item->content());
            $cmd = $this->wikiShowCommand($slug);
            $updated = 'Updated '.Time::ago($item->updatedAt());

            $rows = Rows::wikiPage($title, $cat, $slug, $summary, $cmd, $updated, $selected, $cols, $this->views->twigTui);

            return array_merge($prefix, $rows);
        });

        $this->list = $list;
        $this->updateEmptyText(false);

        $this->filterableList = new FilterableListWidget(
            $this->list,
            fn (string $query, array $all): array => $this->filterPages($query, $all),
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
            if ($item instanceof WikiPage) {
                if ($this->filterableList->isOpen()) {
                    $this->filterableList->close();
                }
                $this->views->navigator()->push(new WikiPageView($this->views, $item->slugString()));
            }
        });

        $this->add($this->filterableList);
    }

    public function title(): string
    {
        return 'Wiki';
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
            ['→/l', 'read'],
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
        if (null === $actor && !$this->views->serverPolicy->anonAccess()->canRead()) {
            $this->filterableList->setAll([]);
            $this->loaded = true;

            return;
        }

        $pages = $this->views->wikiQuery?->all() ?? [];
        $this->filterableList->setAll($pages);
        $this->loaded = true;
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

    private function wikiShowCommand(string $slug): string
    {
        $raw = trim($this->views->settings->sshPublicUrl);
        if ('' === $raw) {
            return 'ssh gickers.sh wiki show '.$slug;
        }

        $url = str_contains($raw, '://') ? $raw : 'ssh://'.$raw;
        $parsed = parse_url($url);
        if (false === $parsed || !isset($parsed['host'])) {
            return 'ssh '.$raw.' wiki show '.$slug;
        }

        $host = $parsed['host'];
        $port = isset($parsed['port']) && 22 !== (int) $parsed['port'] ? (int) $parsed['port'] : null;
        $user = $parsed['user'] ?? null;
        $target = null !== $user && '' !== $user ? "{$user}@{$host}" : $host;
        $prefix = null !== $port ? "ssh -p {$port} {$target}" : "ssh {$target}";

        return $prefix.' wiki show '.$slug;
    }

    private function updateEmptyText(bool $filterOpen): void
    {
        if ($filterOpen) {
            $this->list->setEmptyText("\n  No matching wiki pages.");

            return;
        }

        $this->list->setEmptyText("\n  No wiki pages published yet.");
    }

    private static function extractSummary(string $content): string
    {
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $trimmed = trim($line);
            if ('' === $trimmed || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '```')) {
                continue;
            }

            return trim(ltrim($trimmed, '*>- '));
        }

        return '';
    }

    /**
     * @param list<mixed> $all
     *
     * @return list<mixed>
     */
    private function filterPages(string $query, array $all): array
    {
        $q = trim($query);
        if ('' === $q) {
            return $all;
        }

        $scored = [];
        foreach ($all as $idx => $item) {
            if (!$item instanceof WikiPage) {
                continue;
            }
            $score = Fuzzy::fields($q, [
                ['text' => $item->title(), 'weight' => 3],
                ['text' => $item->slugString(), 'weight' => 3],
                ['text' => $item->category(), 'weight' => 2],
                ['text' => $item->content(), 'weight' => 1],
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

        return array_map(static fn (array $entry): mixed => $entry['item'], $scored);
    }
}

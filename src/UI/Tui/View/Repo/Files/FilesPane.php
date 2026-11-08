<?php

declare(strict_types=1);

namespace App\UI\Tui\View\Repo\Files;

use App\Repo\Domain\Git\TreeEntry;
use App\UI\Tui\Format\Ansi;
use App\UI\Tui\Format\Chrome;
use App\UI\Tui\Format\Rows;
use App\UI\Tui\Format\Size;
use App\UI\Tui\View\Repo\RepoScreen;
use App\UI\Tui\View\Repo\RepoViews;
use App\UI\Tui\Widget\LinesWidget;
use App\UI\Tui\Widget\ListWidget;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\LoaderWidget;

final class FilesPane extends RepoScreen
{
    private readonly ContainerWidget $contentSlot;
    private readonly LinesWidget $crumb;
    private readonly LoaderWidget $loader;
    private readonly ListWidget $list;
    private readonly LinesWidget $emptyWidget;

    public function __construct(RepoViews $repo)
    {
        parent::__construct($repo);

        $this->crumb = new LinesWidget(function (int $cols): array {
            $subpath = $this->repo->state->subpath;
            $path = '' !== $subpath ? '/'.ltrim($subpath, '/') : '/';

            return ["\e[2m".Ansi::fit('  '.$path, $cols)."\e[0m"];
        });
        $this->add($this->crumb);

        $this->contentSlot = new ContainerWidget();
        $this->contentSlot->expandVertically(true);

        $this->loader = new LoaderWidget('Loading files...');
        $this->loader->addStyleClass('indent');
        $this->contentSlot->add($this->loader);

        $list = new ListWidget(static function (mixed $item, bool $selected, int $cols) use (&$list): array {
            if (!$item instanceof TreeEntry || null === $list) {
                return [];
            }

            $mode = self::modeString($item);
            $size = $item->isDirectory() ? '' : Size::human($item->size ?? 0);
            $name = $item->name;

            return Rows::file($mode, $size, $name, $item->isDirectory(), $selected, $cols);
        });
        $this->list = $list;

        $this->list->onSelect(function (mixed $item): void {
            if (!$item instanceof TreeEntry) {
                return;
            }
            if ($item->isDirectory()) {
                $this->repo->state->subpath = $item->path;
                $this->list->setIndex(0);
                $this->loadTree();
            } else {
                $this->repo->navigator()->push(new FileView($this->repo, $item->path));
            }
        });

        $this->emptyWidget = new LinesWidget(static fn (int $cols): array => Chrome::messageBody('Empty directory.', $cols));

        $this->add($this->contentSlot);
    }

    public function title(): string
    {
        return 'Files';
    }

    public function shortcuts(): array
    {
        return [
            'filter' => ['/', 'ctrl+p'],
            'parent' => [Key::BACKSPACE, Key::LEFT, 'h'],
            'open' => [Key::RIGHT, 'l'],
        ];
    }

    public function handleShortcut(string $action): bool
    {
        return match ($action) {
            'filter' => $this->openFuzzy(),
            'parent' => $this->goParent(),
            'open' => $this->list->apply('select'),
            default => false,
        };
    }

    public function keyHints(): array
    {
        return [
            ['/', 'search'],
            ['tab', 'next'],
            ['1–8', 'jump'],
            ['enter', 'open'],
            ['q', 'back'],
        ];
    }

    public function statusValue(): string
    {
        return '';
    }

    public function statusInfo(): string
    {
        if ($this->repo->data->isLoading('tree')) {
            return '…';
        }

        $items = $this->list->items();
        $count = \count($items);
        if (0 === $count) {
            return '';
        }

        return '# '.($this->list->index() + 1).'/'.$count;
    }

    public function reload(): void
    {
        $this->loadTree();
    }

    public function resetNavigation(): void
    {
        $this->repo->state->subpath = '';
        $this->list->setIndex(0);
        $this->loadTree();
    }

    public static function modeString(TreeEntry $entry): string
    {
        $oct = (int) octdec($entry->mode);
        $perm = $oct & 0o777;
        if ($entry->isDirectory()) {
            $type = 'd';
            $perm = 0 !== $perm ? $perm : 0o777;
        } elseif (0o120000 === ($oct & 0o170000)) {
            $type = 'l';
        } else {
            $type = '-';
        }
        $rwx = '';
        foreach ([6, 3, 0] as $shift) {
            $p = ($perm >> $shift) & 7;
            $rwx .= (0 !== ($p & 4) ? 'r' : '-').(0 !== ($p & 2) ? 'w' : '-').(0 !== ($p & 1) ? 'x' : '-');
        }

        return $type.$rwx;
    }

    protected function onActivate(): void
    {
        $this->loadTree();
        $this->focus($this->list);
    }

    private function loadTree(): void
    {
        $this->contentSlot->clear();
        $this->contentSlot->add($this->loader);
        $this->crumb->refresh();

        $subpath = $this->repo->state->subpath;
        $this->repo->data->tree($subpath, function (array $entries): void {
            $this->contentSlot->clear();
            if ([] === $entries) {
                $this->contentSlot->add($this->emptyWidget);
            } else {
                $this->list->setItems($entries);
                $this->contentSlot->add($this->list);
                $this->focus($this->list);
            }
            $this->repo->root()->refreshChrome();
        });
    }

    private function openFuzzy(): bool
    {
        $this->repo->navigator()->push(new FuzzyFileView($this->repo, $this));

        return true;
    }

    private function goParent(): bool
    {
        $sub = $this->repo->state->subpath;
        if ('' === $sub) {
            return false;
        }

        $parts = explode('/', trim($sub, '/'));
        array_pop($parts);
        $this->repo->state->subpath = [] !== $parts ? implode('/', $parts) : '';
        $this->list->setIndex(0);
        $this->loadTree();

        return true;
    }
}

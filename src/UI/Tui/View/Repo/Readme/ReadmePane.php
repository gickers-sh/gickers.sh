<?php

declare(strict_types=1);

namespace App\UI\Tui\View\Repo\Readme;

use App\UI\Tui\View\Repo\RepoScreen;
use App\UI\Tui\View\Repo\RepoViews;
use App\UI\Tui\Widget\DocumentWidget;

final class ReadmePane extends RepoScreen
{
    private readonly DocumentWidget $doc;
    private bool $loaded = false;

    public function __construct(RepoViews $repo)
    {
        parent::__construct($repo);

        $this->doc = new DocumentWidget($this->repo->views->renderer, $this->repo->async());
        $this->doc->expandVertically(true);

        $this->doc->onLink(function (string $url): void {
            $this->repo->flash = '🔗 '.$url;
            $this->repo->root()->refreshChrome();
        });

        $this->add($this->doc);
    }

    public function title(): string
    {
        return 'Readme';
    }

    public function shortcuts(): array
    {
        return [];
    }

    public function handleShortcut(string $action): bool
    {
        return false;
    }

    public function keyHints(): array
    {
        return [
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
        if ($this->repo->data->isLoading('readme')) {
            return '…';
        }

        $scrollWidget = $this->doc->scrollWidget();
        $lineCount = \count($scrollWidget->lines());
        if (0 === $lineCount) {
            return '☰ 0%';
        }

        $rows = max(1, $scrollWidget->rows());
        $maxScroll = max(0, $lineCount - $rows);
        $scroll = $scrollWidget->scroll();
        $percent = $maxScroll > 0 ? (int) round(min($scroll, $maxScroll) / $maxScroll * 100) : 100;

        return sprintf('☰ %d%%', $percent);
    }

    public function resetNavigation(): void
    {
        $this->doc->scrollWidget()->setScroll(0);
    }

    protected function onActivate(): void
    {
        if ($this->loaded) {
            $this->focus($this->doc->scrollWidget());

            return;
        }
        $this->loadReadme();
        $this->focus($this->doc->scrollWidget());
    }

    private function loadReadme(): void
    {
        $this->doc->showLoading('Loading README...');

        $this->repo->data->readme(function (?array $doc): void {
            $this->loaded = true;
            if (null === $doc) {
                $this->doc->setLines(['', '  This repository has no README.']);
            } else {
                $this->doc->setDocument($doc['path'], $doc['content']);
            }
            $this->repo->root()->refreshChrome();
        });
    }
}

<?php

declare(strict_types=1);

namespace App\UI\Tui\Page\Repo;

use App\UI\Tui\Input\Keys;
use App\UI\Tui\Render\Screen;

/**
 * Commits tab: a list of recent commits, opening a scrollable coloured diff.
 */
final class CommitsTab extends AbstractRepoTab
{
    private bool $diffOpen = false;
    /** @var list<string> */
    private array $diffLines = [];

    public function capturesInput(): bool
    {
        return $this->diffOpen;
    }

    public function onActivate(): void
    {
        parent::onActivate();
        $this->diffOpen = false;
    }

    public function footerText(): string
    {
        if ($this->diffOpen) {
            return ' ↑/↓ scroll · Esc: back';
        }

        return self::BASE_FOOTER;
    }

    public function handleKey(string $seq): bool
    {
        if ($this->diffOpen) {
            return $this->handleDiffKey($seq);
        }

        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);
        $commits = $this->ctx->commitList();
        if (Keys::ENTER === $tok && [] !== $commits) {
            $this->openDiff($commits[min($this->sel, \count($commits) - 1)]->hash);

            return true;
        }

        return $this->handleListMove($tok, $txt, \count($commits));
    }

    private function handleDiffKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);
        if (Keys::ESCAPE === $tok || 'q' === $txt) {
            $this->diffOpen = false;

            return true;
        }

        return $this->handleDocScroll($tok, $txt, \count($this->diffLines));
    }

    private function openDiff(string $sha): void
    {
        try {
            $diff = $this->ctx->reader->getDiff($this->ctx->git, $sha);
        } catch (\Throwable) {
            $this->ctx->flash = 'Cannot load diff.';

            return;
        }
        $this->diffLines = preg_split('/\r\n|\r|\n/', rtrim($diff->rawDiff, "\n")) ?: [];
        $this->diffOpen = true;
        $this->scroll = 0;
    }

    public function paintBody(int $cols, int $rows): array
    {
        if ($this->diffOpen) {
            return $this->docWindow($this->colourDiff($this->diffLines), $cols, $rows);
        }

        $commits = $this->ctx->commitList();
        if ([] === $commits) {
            return $this->messageBody('No commits.', $cols);
        }

        $count = \count($commits);
        $sel = min($this->sel, $count - 1);
        $blocks = [];
        $blockStart = [];
        $lines = [];
        foreach ($commits as $i => $commit) {
            $meta = $commit->authorName.' committed on '.date('M j', $commit->timestamp);
            $blocks[] = Screen::commitItem($commit->subject, $meta, $commit->shortHash, $i === $sel, $cols);
        }
        foreach ($blocks as $bi => $block) {
            if ($bi > 0) {
                $lines[] = '';
            }
            $blockStart[$bi] = \count($lines);
            foreach ($block as $l) {
                $lines[] = $l;
            }
        }

        $offset = 0;
        if (\count($lines) > $rows) {
            $offset = max(0, min(($blockStart[$sel] ?? 0) - 1, \count($lines) - $rows));
        }
        $view = \array_slice($lines, $offset, $rows);
        while (\count($view) < $rows) {
            $view[] = Screen::blank($cols);
        }

        return $view;
    }

    public function statusValue(): string
    {
        $commits = $this->ctx->commitList();
        if ([] === $commits) {
            return '';
        }
        $c = $commits[min($this->sel, \count($commits) - 1)];

        return $c->shortHash.' by '.$c->authorName.' <'.$c->authorEmail.'>';
    }

    public function statusInfo(): string
    {
        $count = \count($this->ctx->commitList());
        if (0 === $count) {
            return '';
        }

        return 'p. '.(min($this->sel, $count - 1) + 1).'/'.$count;
    }
}

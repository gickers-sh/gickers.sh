<?php

declare(strict_types=1);

namespace App\UI\Tui\Widget;

use App\UI\Tui\Render\Screen;
use App\UI\Tui\Render\TermstrapRenderer;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * Renders documents (Markdown, CSV, TSV, HTML) into formatted ANSI lines using
 * {@see TermstrapRenderer}, with scrolling and terminal-width wrapping.
 */
final class AnsiDocumentWidget extends AbstractWidget implements VerticallyExpandableInterface
{
    private bool $expanded = true;
    private string $filename = '';
    private string $content = '';
    private int $scroll = 0;

    /** @var array{key: string, lines: list<string>}|null */
    private ?array $cache = null;

    public function __construct(
        private readonly TermstrapRenderer $renderer,
    ) {
    }

    public function setDocument(string $filename, string $content): void
    {
        if ($this->filename !== $filename || $this->content !== $content) {
            $this->filename = $filename;
            $this->content = $content;
            $this->cache = null;
            $this->scroll = 0;
            $this->invalidate();
        }
    }

    public function setScroll(int $scroll): void
    {
        $this->scroll = max(0, $scroll);
        $this->invalidate();
    }

    public function getScroll(): int
    {
        return $this->scroll;
    }

    public function scrollBy(int $delta): void
    {
        $this->setScroll($this->scroll + $delta);
    }

    /**
     * @return list<string>
     */
    public function getLines(int $cols): array
    {
        if ('' === $this->content) {
            return [];
        }

        $key = $this->filename.':'.$cols;
        if (null === $this->cache || $this->cache['key'] !== $key) {
            if ($this->renderer->isAvailable()) {
                $ansi = $this->renderer->renderDocument($this->filename, $this->content, max(20, $cols - 2));
                $lines = preg_split('/\r\n|\r|\n/', rtrim($ansi, "\n")) ?: [];
            } else {
                $lines = preg_split('/\r\n|\r|\n/', rtrim($this->content, "\n")) ?: [];
            }
            $this->cache = ['key' => $key, 'lines' => $lines];
        }

        return $this->cache['lines'];
    }

    public function lineCount(int $cols = 80): int
    {
        return \count($this->getLines($cols));
    }

    public function expandVertically(bool $expand): static
    {
        if ($this->expanded !== $expand) {
            $this->expanded = $expand;
            $this->invalidate();
        }

        return $this;
    }

    public function isVerticallyExpanded(): bool
    {
        return $this->expanded;
    }

    public function render(RenderContext $context): array
    {
        $cols = max(0, $context->getColumns());
        $rows = max(0, $context->getRows());

        $lines = $this->getLines($cols);
        $total = \count($lines);
        $top = max(0, min($this->scroll, max(0, $total - 1)));

        $out = [];
        for ($r = 0; $r < $rows; ++$r) {
            $idx = $top + $r;
            $out[] = $idx < $total ? Screen::fit($lines[$idx], $cols) : Screen::blank($cols);
        }

        return $out;
    }
}

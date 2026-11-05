<?php

declare(strict_types=1);

namespace App\UI\Tui\Widget;

use App\UI\Tui\Render\Screen;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * A leaf widget that fills the available body area with lines produced by a
 * painter closure, which receives the allocated (columns, rows) at render time.
 *
 * `symfony/tui` 8.1 ships no scroll/viewport/list widgets, so the pages compute
 * their own body (lists, code viewer, rendered documents, modals) through
 * {@see Screen} and hand a painter here. Every produced line is re-fitted to the
 * viewport width because the Renderer rejects lines wider than the terminal.
 */
final class ContentWidget extends AbstractWidget implements VerticallyExpandableInterface
{
    private bool $expanded = true;

    /** @var \Closure(int, int): list<string> */
    private \Closure $painter;

    public function __construct()
    {
        $this->painter = static fn (int $cols, int $rows): array => [];
    }

    /**
     * @param callable(int $cols, int $rows): list<string> $painter
     */
    public function setPainter(callable $painter): void
    {
        $this->painter = $painter(...);
        $this->invalidate();
    }

    /**
     * Bust the render cache so the next pass re-invokes the painter with fresh page state.
     */
    public function markDirty(): void
    {
        $this->invalidate();
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
        $inner = max(0, $cols - 2 * Screen::MARGIN);
        $pad = str_repeat(' ', Screen::MARGIN);

        $out = [];
        foreach (($this->painter)($inner, $rows) as $line) {
            if ($this->expanded && \count($out) >= $rows) {
                break;
            }
            $out[] = Screen::fit($pad.$line, $cols);
        }

        if ($this->expanded) {
            while (\count($out) < $rows) {
                $out[] = Screen::blank($cols);
            }
        }

        return $out;
    }
}

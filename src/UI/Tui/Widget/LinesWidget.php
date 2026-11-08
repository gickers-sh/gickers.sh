<?php

declare(strict_types=1);

namespace App\UI\Tui\Widget;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Fixed-height, width-aware lines: the painter receives the available columns
 * and returns the rows to show (headers with right-aligned segments, status
 * bars, blank spacer rows). Lines wider than the space are truncated.
 */
final class LinesWidget extends AbstractWidget
{
    /** @var \Closure(int): list<string> */
    private \Closure $painter;

    /**
     * @param (\Closure(int $cols): list<string>)|list<string> $painter
     */
    public function __construct(\Closure|array $painter = [])
    {
        $this->setPainter($painter);
    }

    /**
     * @param (\Closure(int $cols): list<string>)|list<string> $painter
     */
    public function setPainter(\Closure|array $painter): static
    {
        $this->painter = $painter instanceof \Closure ? $painter : static fn (int $cols): array => $painter;
        $this->invalidate();

        return $this;
    }

    /**
     * Re-run the painter on the next render (its inputs changed).
     */
    public function refresh(): void
    {
        $this->invalidate();
    }

    public function render(RenderContext $context): array
    {
        $cols = max(1, $context->getColumns());
        $out = [];
        foreach (($this->painter)($cols) as $line) {
            $out[] = AnsiUtils::truncateToWidth($line, $cols, '');
        }

        return $out;
    }
}

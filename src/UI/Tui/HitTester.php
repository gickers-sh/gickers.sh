<?php

declare(strict_types=1);

namespace App\UI\Tui;

use App\UI\Tui\Widget\Clickable;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ParentInterface;
use Symfony\Component\Tui\Widget\WidgetContainerInterface;

/**
 * Maps a terminal cell to the deepest {@see Clickable} widget of the top view,
 * using the rects the {@see Renderer} tracked during the last render.
 *
 * The Renderer is framework-internal; this class is the only one relying on it.
 */
final class HitTester
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly Navigator $navigator,
        private readonly Tui $tui,
    ) {
    }

    /**
     * @param int $x 1-based terminal column (SGR)
     * @param int $y 1-based terminal row (SGR)
     */
    public function click(int $x, int $y, bool $nav): bool
    {
        $row = $y - 1;
        $col = $x - 1;
        $best = null;
        $bestDepth = -1;
        $bestRect = null;
        $this->walk($this->navigator->top(), 0, function (AbstractWidget $w, int $depth) use ($row, $col, &$best, &$bestDepth, &$bestRect): void {
            if (!$w instanceof Clickable || $depth <= $bestDepth) {
                return;
            }
            $rect = $this->renderer->getWidgetRect($w);
            if (null !== $rect && $rect->contains($row, $col)) {
                $best = $w;
                $bestDepth = $depth;
                $bestRect = $rect;
            }
        });
        if (!$best instanceof Clickable || null === $bestRect) {
            return false;
        }
        $rel = $bestRect->toRelative($row, $col);
        $handled = $best->click($rel['row'], $rel['col'], $nav);
        $this->tui->requestRender();

        return $handled;
    }

    /**
     * Resolves the deepest widget at given terminal coordinates (1-based),
     * returning [widget => AbstractWidget, rect => WidgetRect] or null.
     *
     * @param int $x 1-based column
     * @param int $y 1-based row
     * @return array{widget: AbstractWidget, rect: \Symfony\Component\Tui\Render\WidgetRect}|null
     */
    public function findAt(int $x, int $y): ?array
    {
        $row = $y - 1;
        $col = $x - 1;
        $best = null;
        $bestDepth = -1;
        $bestRect = null;
        $this->walk($this->navigator->top(), 0, function (AbstractWidget $w, int $depth) use ($row, $col, &$best, &$bestDepth, &$bestRect): void {
            if ($depth <= $bestDepth) {
                return;
            }
            $rect = $this->renderer->getWidgetRect($w);
            if (null !== $rect && $rect->contains($row, $col)) {
                $best = $w;
                $bestDepth = $depth;
                $bestRect = $rect;
            }
        });

        if (null === $best || null === $bestRect) {
            return null;
        }

        return ['widget' => $best, 'rect' => $bestRect];
    }

    /**
     * @param \Closure(AbstractWidget, int): void $visit
     */
    private function walk(AbstractWidget $widget, int $depth, \Closure $visit): void
    {
        if (true === $this->renderer->resolveStyle($widget)->getHidden()) {
            return;
        }
        $visit($widget, $depth);
        if ($widget instanceof WidgetContainerInterface || $widget instanceof ParentInterface) {
            foreach ($widget->all() as $child) {
                $this->walk($child, $depth + 1, $visit);
            }
        }
    }
}

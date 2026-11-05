<?php

declare(strict_types=1);

namespace App\UI\Tui\Widget;

use App\UI\Tui\Render\Screen;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * A width-aware, fixed-height leaf widget: its painter receives the inner
 * content width (terminal width minus the page's left/right margin) and returns
 * the exact lines to display. Used for the page chrome (header, tab bar, status
 * bar / help footer) which needs the real width to right-align the clone URL and
 * stretch the status bar, something {@see \Symfony\Component\Tui\Widget\TextWidget}
 * cannot do because it is handed fixed text.
 *
 * Unlike {@see ContentWidget} it does not expand vertically; the container sizes
 * it to the number of lines the painter returns.
 */
final class BarWidget extends AbstractWidget
{
    /** @var \Closure(int): list<string> */
    private \Closure $painter;

    /**
     * @param callable(int $cols): list<string> $painter
     */
    public function __construct(callable $painter)
    {
        $this->painter = $painter(...);
    }

    public function markDirty(): void
    {
        $this->invalidate();
    }

    public function render(RenderContext $context): array
    {
        $cols = max(0, $context->getColumns());
        $inner = max(0, $cols - 2 * Screen::MARGIN);
        $pad = str_repeat(' ', Screen::MARGIN);

        $out = [];
        foreach (($this->painter)($inner) as $line) {
            $out[] = Screen::fit($pad.$line, $cols);
        }

        return $out;
    }
}

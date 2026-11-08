<?php

declare(strict_types=1);

namespace App\UI\Tui\Widget;

use App\UI\Tui\Format\Ansi;
use App\UI\Tui\Format\Chrome;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * A one-line tab bar, clickable per label.
 *
 * Styles: {@see self::BAR} (repository tabs: labels joined by " │ ", active
 * cyan underlined, {@see Chrome::tabBar()}) and {@see self::DOTS} (top-level
 * tabs: active prefixed with "• ", three-space gaps, {@see Chrome::tabBarDots()}).
 */
final class TabBarWidget extends AbstractWidget implements Clickable
{
    public const BAR = 'bar';
    public const DOTS = 'dots';

    private int $active = 0;
    /** @var \Closure(int): void|null */
    private ?\Closure $onSelect = null;

    /**
     * @param list<string> $labels
     * @param int          $blankAfter blank rows rendered below the bar
     */
    public function __construct(
        private readonly array $labels,
        private readonly string $style = self::BAR,
        private readonly int $blankAfter = 0,
    ) {
    }

    public function setActive(int $i): void
    {
        if ($i !== $this->active) {
            $this->active = $i;
            $this->invalidate();
        }
    }

    public function active(): int
    {
        return $this->active;
    }

    /**
     * @return list<string>
     */
    public function labels(): array
    {
        return $this->labels;
    }

    /**
     * @param \Closure(int $index): void $fn
     */
    public function onSelect(\Closure $fn): static
    {
        $this->onSelect = $fn;

        return $this;
    }

    public function click(int $row, int $col, bool $nav): bool
    {
        if (0 !== $row) {
            return false;
        }
        $pos = 0;
        foreach ($this->labels as $i => $label) {
            $len = Ansi::width($label) + (self::DOTS === $this->style && $i === $this->active ? 2 : 0);
            if ($col >= $pos && $col < $pos + $len) {
                if ($i !== $this->active && null !== $this->onSelect) {
                    ($this->onSelect)($i);
                }

                return true;
            }
            $pos += $len + 3;
        }

        return false;
    }

    public function render(RenderContext $context): array
    {
        $cols = max(1, $context->getColumns());
        $line = self::DOTS === $this->style
            ? Chrome::tabBarDots($this->labels, $this->active, $cols)
            : Chrome::tabBar($this->labels, $this->active, $cols);
        $out = [AnsiUtils::truncateToWidth($line, $cols, '')];
        for ($i = 0; $i < $this->blankAfter; ++$i) {
            $out[] = '';
        }

        return $out;
    }
}

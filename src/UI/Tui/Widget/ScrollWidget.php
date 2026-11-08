<?php

declare(strict_types=1);

namespace App\UI\Tui\Widget;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * A scrollable viewport over pre-formatted ANSI lines (documents, diffs, code).
 *
 * Keys (less-style): ↑/k ↓/j, Ctrl+D/d Ctrl+U/u (half page), PgDn/Ctrl+F/f/Space
 * and PgUp/Ctrl+B/b (page), Home/g, End/G.
 */
final class ScrollWidget extends AbstractWidget implements FocusableInterface, VerticallyExpandableInterface, Clickable
{
    use FocusableTrait;
    use KeybindingsTrait;

    /** @var list<string> */
    private array $lines = [];
    private int $scroll = 0;
    private int $rows = 20;
    private bool $expanded = true;
    /** @var \Closure(int, int, bool): bool|null */
    private ?\Closure $onClickLine = null;

    /**
     * @param list<string> $lines
     */
    public function setLines(array $lines): static
    {
        $this->lines = $lines;
        $this->scroll = max(0, min($this->scroll, $this->maxScroll()));
        $this->invalidate();

        return $this;
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function scroll(): int
    {
        return $this->scroll;
    }

    public function setScroll(int $s): static
    {
        $s = max(0, min($s, $this->maxScroll()));
        if ($s !== $this->scroll) {
            $this->scroll = $s;
            $this->invalidate();
        }

        return $this;
    }

    /**
     * Viewport height from the last render.
     */
    public function rows(): int
    {
        return $this->rows;
    }

    /**
     * @param \Closure(int $lineIndex, int $col, bool $nav): bool $fn
     */
    public function onClickLine(\Closure $fn): static
    {
        $this->onClickLine = $fn;

        return $this;
    }

    /**
     * Apply a scroll action (up, down, half_down, half_up, page_down, page_up, top, bottom).
     */
    public function apply(string $action): bool
    {
        $half = max(1, (int) round($this->rows / 2));
        $page = max(1, $this->rows - 2);
        match ($action) {
            'up' => $this->setScroll($this->scroll - 1),
            'down' => $this->setScroll($this->scroll + 1),
            'half_down' => $this->setScroll($this->scroll + $half),
            'half_up' => $this->setScroll($this->scroll - $half),
            'page_down' => $this->setScroll($this->scroll + $page),
            'page_up' => $this->setScroll($this->scroll - $page),
            'top' => $this->setScroll(0),
            'bottom' => $this->setScroll($this->maxScroll()),
            default => null,
        };

        return isset(self::getDefaultKeybindings()[$action]);
    }

    public function handleInput(string $data): void
    {
        if (null !== $this->onInput && ($this->onInput)($data)) {
            return;
        }
        $kb = $this->getKeybindings();
        foreach (array_keys(self::getDefaultKeybindings()) as $action) {
            if ($kb->matches($data, $action)) {
                $this->apply($action);

                return;
            }
        }
    }

    public function click(int $row, int $col, bool $nav): bool
    {
        $line = $this->scroll + $row;
        if (null === $this->onClickLine || $line >= \count($this->lines)) {
            return false;
        }

        return ($this->onClickLine)($line, $col, $nav);
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
        $cols = max(1, $context->getColumns());
        $this->rows = max(1, $context->getRows());
        $this->scroll = max(0, min($this->scroll, $this->maxScroll()));
        $out = [];
        foreach (\array_slice($this->lines, $this->scroll, $this->rows) as $line) {
            $out[] = AnsiUtils::truncateToWidth($line, $cols, '');
        }

        return $out;
    }

    /**
     * @return array<string, string[]>
     */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'up' => [Key::UP, 'k'],
            'down' => [Key::DOWN, 'j'],
            'half_down' => ['ctrl+d', 'd'],
            'half_up' => ['ctrl+u', 'u'],
            'page_down' => [Key::PAGE_DOWN, 'ctrl+f', 'f', Key::SPACE],
            'page_up' => [Key::PAGE_UP, 'ctrl+b', 'b'],
            'top' => [Key::HOME, 'g'],
            'bottom' => [Key::END, 'shift+g'],
        ];
    }

    private function maxScroll(): int
    {
        return max(0, \count($this->lines) - $this->rows);
    }
}

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
 * A scrolling list of pre-formatted, possibly multi-line rows.
 *
 * Each item is rendered by the row closure (`fn(mixed $item, bool $selected,
 * int $cols): list<string>`). The window scrolls minimally to keep the
 * selected item visible. Keys: ↑/k, ↓/j, PgUp/PgDn (10), Home/g, End/G, Enter.
 */
final class ListWidget extends AbstractWidget implements FocusableInterface, VerticallyExpandableInterface, Clickable
{
    use FocusableTrait;
    use KeybindingsTrait;

    private const PAGE = 10;

    /** @var list<mixed> */
    private array $items = [];
    private int $index = 0;
    private int $top = 0;
    private string $emptyText = '';
    private bool $expanded = true;
    /** @var array<int, int> rendered row => item index */
    private array $rowMap = [];
    /** @var \Closure(mixed, int): void|null */
    private ?\Closure $onSelect = null;
    /** @var \Closure(mixed, int): void|null */
    private ?\Closure $onHighlight = null;

    /**
     * @param \Closure(mixed $item, bool $selected, int $cols): list<string> $row
     */
    public function __construct(private readonly \Closure $row)
    {
    }

    /**
     * Replace the items; the selection index is kept (clamped), not reset.
     *
     * @param list<mixed> $items
     */
    public function setItems(array $items): static
    {
        $this->items = $items;
        $this->index = max(0, min($this->index, \count($this->items) - 1));
        $this->invalidate();

        return $this;
    }

    /**
     * @return list<mixed>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function selected(): mixed
    {
        return $this->items[$this->index] ?? null;
    }

    public function index(): int
    {
        return $this->index;
    }

    public function setIndex(int $i): static
    {
        $i = max(0, min($i, \count($this->items) - 1));
        if ($i !== $this->index) {
            $this->index = $i;
            $this->invalidate();
            if ([] !== $this->items && null !== $this->onHighlight) {
                ($this->onHighlight)($this->items[$i], $i);
            }
        }

        return $this;
    }

    /**
     * @param \Closure(mixed $item, int $index): void $fn
     */
    public function onSelect(\Closure $fn): static
    {
        $this->onSelect = $fn;

        return $this;
    }

    /**
     * @param \Closure(mixed $item, int $index): void $fn
     */
    public function onHighlight(\Closure $fn): static
    {
        $this->onHighlight = $fn;

        return $this;
    }

    public function setEmptyText(string $t): static
    {
        if ($t !== $this->emptyText) {
            $this->emptyText = $t;
            $this->invalidate();
        }

        return $this;
    }

    /**
     * Fire the select callback for the current item (no-op when empty).
     */
    public function select(): void
    {
        if ([] !== $this->items && null !== $this->onSelect) {
            ($this->onSelect)($this->items[$this->index], $this->index);
        }
    }

    /**
     * Apply a navigation action (up, down, page_up, page_down, first, last, select).
     */
    public function apply(string $action): bool
    {
        $last = max(0, \count($this->items) - 1);
        match ($action) {
            'up' => $this->setIndex($this->index - 1),
            'down' => $this->setIndex(min($this->index + 1, $last)),
            'page_up' => $this->setIndex($this->index - self::PAGE),
            'page_down' => $this->setIndex(min($this->index + self::PAGE, $last)),
            'first' => $this->setIndex(0),
            'last' => $this->setIndex($last),
            'select' => $this->select(),
            default => null,
        };

        return \in_array($action, ['up', 'down', 'page_up', 'page_down', 'first', 'last', 'select'], true);
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

    /**
     * A click selects the row; it opens (fires onSelect) only when the row was
     * already selected or on a navigation click, as the old list tabs did.
     */
    public function click(int $row, int $col, bool $nav): bool
    {
        if (!isset($this->rowMap[$row])) {
            return false;
        }
        $wasSelected = $this->rowMap[$row] === $this->index;
        $this->setIndex($this->rowMap[$row]);
        if ($nav || $wasSelected) {
            $this->select();
        }

        return true;
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
        $rows = max(1, $context->getRows());
        $this->rowMap = [];

        if ([] === $this->items) {
            if ('' === $this->emptyText) {
                return [];
            }
            $out = [];
            foreach (explode("\n", $this->emptyText) as $line) {
                $out[] = AnsiUtils::truncateToWidth($line, $cols, '');
            }

            return \array_slice($out, 0, $rows);
        }

        /** @var array<int, list<string>> $cache */
        $cache = [];
        $linesOf = function (int $i) use (&$cache, $cols): array {
            return $cache[$i] ??= ($this->row)($this->items[$i], $i === $this->index, $cols);
        };

        $this->top = max(0, min($this->top, $this->index));
        $height = 0;
        for ($i = $this->top; $i <= $this->index; ++$i) {
            $height += \count($linesOf($i));
        }
        while ($height > $rows && $this->top < $this->index) {
            $height -= \count($linesOf($this->top));
            ++$this->top;
        }

        $out = [];
        $count = \count($this->items);
        for ($i = $this->top; $i < $count && \count($out) < $rows; ++$i) {
            foreach ($linesOf($i) as $line) {
                if (\count($out) >= $rows) {
                    break;
                }
                $this->rowMap[\count($out)] = $i;
                $out[] = AnsiUtils::truncateToWidth($line, $cols, '');
            }
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
            'page_up' => [Key::PAGE_UP],
            'page_down' => [Key::PAGE_DOWN],
            'first' => [Key::HOME, 'g'],
            'last' => [Key::END, 'shift+g'],
            'select' => [Key::ENTER],
        ];
    }
}

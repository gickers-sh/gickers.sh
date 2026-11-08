<?php

declare(strict_types=1);

namespace App\UI\Tui\Widget;

use App\UI\Tui\Render\Theme;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\ChangeEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\WidgetContext;

/**
 * A {@see ListWidget} with an on-demand fuzzy filter input above it.
 *
 * {@see open()} shows and focuses the input; typing re-filters the full item
 * set through the filter closure. In the input: ↑/Ctrl+P ↓/Ctrl+N PgUp PgDn
 * Home End move the list selection, Enter selects, Esc or Backspace on an empty
 * query closes, Ctrl+U clears. While the source is loading only text edits apply.
 */
final class FilterableListWidget extends ContainerWidget
{
    private readonly InputWidget $input;
    private readonly ContainerWidget $inputSlot;
    /** @var list<mixed> */
    private array $all = [];
    private bool $loading = false;
    /** @var \Closure(bool): void|null */
    private ?\Closure $onToggle = null;
    /** @var \Closure(string): bool|null */
    private ?\Closure $onKey = null;

    /**
     * @param \Closure(string $query, list<mixed> $all): list<mixed> $filter
     */
    public function __construct(
        private readonly ListWidget $list,
        private readonly \Closure $filter,
    ) {
        $this->expandVertically(true);
        $this->input = new InputWidget();
        $this->input->addStyleClass('fuzzy');
        $this->input->setPrompt('  '.Theme::style('/', Theme::HIGHLIGHT, bold: true).' ');
        $this->input->onInput(fn (string $data): bool => $this->intercept($data));
        $this->inputSlot = new ContainerWidget();
        $this->add($this->inputSlot);
        $this->add($this->list);
    }

    public function list(): ListWidget
    {
        return $this->list;
    }

    public function input(): InputWidget
    {
        return $this->input;
    }

    /**
     * @param list<mixed> $all
     */
    public function setAll(array $all): void
    {
        $this->all = $all;
        $this->list->setItems($this->isOpen() ? ($this->filter)($this->input->getValue(), $this->all) : $this->all);
    }

    /**
     * @return list<mixed> the unfiltered items
     */
    public function allItems(): array
    {
        return $this->all;
    }

    public function setLoading(bool $loading): void
    {
        $this->loading = $loading;
    }

    /**
     * @param \Closure(bool $open): void $fn called after the filter opens or closes
     */
    public function onToggle(\Closure $fn): static
    {
        $this->onToggle = $fn;

        return $this;
    }

    /**
     * Pre-filter hook for raw input reaching the filter field; return true to
     * consume it (e.g. Tab closing the filter and switching tabs).
     *
     * @param \Closure(string $data): bool $fn
     */
    public function onKey(\Closure $fn): static
    {
        $this->onKey = $fn;

        return $this;
    }

    public function query(): string
    {
        return $this->isOpen() ? $this->input->getValue() : '';
    }

    public function open(): void
    {
        if (!$this->isOpen()) {
            $this->inputSlot->add($this->input);
        }
        $this->bindInput();
        $this->input->setValue('');
        $this->refilter();
        $focus = $this->getContext()?->getFocusManager();
        $focus?->setFocus($this->input);
        // While filtering, Tab must reach the input (onKey) instead of cycling to the list.
        $focus?->remove($this->list);
        if (null !== $this->onToggle) {
            ($this->onToggle)(true);
        }
    }

    public function close(): void
    {
        if ($this->isOpen()) {
            $this->inputSlot->remove($this->input);
        }
        $this->input->setValue('');
        $this->list->setItems($this->all);
        $this->list->setIndex(0);
        $focus = $this->getContext()?->getFocusManager();
        $focus?->add($this->list);
        $focus?->setFocus($this->list);
        if (null !== $this->onToggle) {
            ($this->onToggle)(false);
        }
    }

    public function isOpen(): bool
    {
        return [] !== $this->inputSlot->all();
    }

    protected function onAttach(WidgetContext $context): void
    {
        $this->bindInput();
    }

    private function bindInput(): void
    {
        if ($this->input->hasListeners(ChangeEvent::class)) {
            return;
        }
        $this->input->onChange(fn (ChangeEvent $e) => $this->refilter());
        $this->input->onSubmit(fn (SubmitEvent $e) => $this->list->select());
        $this->input->onCancel(fn (CancelEvent $e) => $this->close());
    }

    private function refilter(): void
    {
        $this->list->setItems(($this->filter)($this->input->getValue(), $this->all));
        $this->list->setIndex(0);
    }

    private function intercept(string $data): bool
    {
        if (null !== $this->onKey && ($this->onKey)($data)) {
            return true;
        }
        $kb = new Keybindings([
            'up' => [Key::UP, 'ctrl+p'],
            'down' => [Key::DOWN, 'ctrl+n'],
            'page_up' => [Key::PAGE_UP],
            'page_down' => [Key::PAGE_DOWN],
            'first' => [Key::HOME],
            'last' => [Key::END],
            'backspace' => [Key::BACKSPACE],
            'clear' => ['ctrl+u'],
        ], $this->input->getKeybindings()->getParser());

        if ($kb->matches($data, 'clear')) {
            $this->input->setValue('');
            $this->refilter();

            return true;
        }
        if ($kb->matches($data, 'backspace') && '' === $this->input->getValue()) {
            $this->close();

            return true;
        }
        foreach (['up', 'down', 'page_up', 'page_down', 'first', 'last'] as $action) {
            if ($kb->matches($data, $action)) {
                if (!$this->loading) {
                    $this->list->apply($action);
                }

                return true;
            }
        }

        return false;
    }
}

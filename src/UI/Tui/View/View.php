<?php

declare(strict_types=1);

namespace App\UI\Tui\View;

use Symfony\Component\Tui\Event\AbstractEvent;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * One screen (or pane of a screen) of the TUI.
 *
 * Views compose child widgets with {@see add()} and never render ANSI
 * themselves: the Renderer lays out containers. Keyboard shortcuts are named
 * actions resolved by {@see \App\UI\Tui\InputRouter}: the router walks the top
 * view and its {@see activeChild()} chain (outermost first) and calls
 * {@see handleShortcut()} for the first matching action.
 *
 * Symfony widget listeners registered through {@see on()} are dropped whenever
 * the widget detaches (pushing another view clears the tree). Register them with
 * {@see listen()} instead: they are re-attached on every {@see activate()}.
 */
abstract class View extends ContainerWidget
{
    /** @var list<array{AbstractWidget, class-string<AbstractEvent>, callable}> */
    private array $bindings = [];

    abstract public function title(): string;

    /**
     * @return array<string, list<string>> action => key ids (Key::UP, 'ctrl+s', 'q', …)
     */
    public function shortcuts(): array
    {
        return [];
    }

    public function handleShortcut(string $action): bool
    {
        return false;
    }

    /**
     * Called by the router with every key routed to this view while it is the
     * top of the stack, before shortcut matching. Return true to consume.
     */
    public function onAnyKey(string $data): bool
    {
        return false;
    }

    /**
     * The nested view receiving shortcuts after this one (e.g. the active pane).
     */
    public function activeChild(): ?self
    {
        return null;
    }

    /**
     * Footer key hints.
     *
     * @return list<array{string, string}> [keyLabel, description]
     */
    public function keyHints(): array
    {
        return [];
    }

    /**
     * Columns of the full help grid shown by {@see Common\HelpView}.
     *
     * @return list<list<array{string, string}>>
     */
    public function helpColumns(): array
    {
        $hints = $this->keyHints();

        return [] === $hints ? [] : [$hints];
    }

    /**
     * Mounted (again) on screen: re-binds widget listeners, then runs
     * {@see onActivate()} (register focus, refresh data). Idempotent.
     */
    final public function activate(): void
    {
        $pending = [];
        foreach ($this->bindings as [$widget, $event, $listener]) {
            $key = spl_object_id($widget).'|'.$event;
            if (!isset($pending[$key])) {
                $pending[$key] = !$widget->hasListeners($event);
            }
            if ($pending[$key]) {
                $widget->on($event, $listener);
            }
        }
        $this->onActivate();
    }

    /**
     * Left the navigation stack for good (popped, replaced or reset).
     */
    public function deactivate(): void
    {
    }

    protected function onActivate(): void
    {
    }

    /**
     * Register a widget event listener that survives detach/re-attach cycles.
     *
     * @param class-string<AbstractEvent> $eventClass
     */
    final protected function listen(AbstractWidget $widget, string $eventClass, callable $listener): void
    {
        $this->bindings[] = [$widget, $eventClass, $listener];
        $widget->on($eventClass, $listener);
    }

    final protected function focus(AbstractWidget $widget): void
    {
        $this->getContext()?->getFocusManager()->setFocus($widget);
    }
}

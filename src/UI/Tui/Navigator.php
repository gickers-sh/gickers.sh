<?php

declare(strict_types=1);

namespace App\UI\Tui;

use App\UI\Tui\View\View;
use Symfony\Component\Tui\Tui;

/**
 * Stack of full-screen views. Only the top view is mounted in the {@see Tui}
 * tree; views below keep their state and are re-mounted (and re-activated)
 * when they become the top again.
 */
final class Navigator
{
    /** @var list<View> */
    private array $stack = [];

    public function __construct(
        private readonly Tui $tui,
        private readonly AsyncLoader $async,
    ) {
    }

    public function reset(View $view): void
    {
        while ([] !== $this->stack) {
            $this->leave(array_pop($this->stack));
        }
        $this->stack = [$view];
        $this->mount($view);
    }

    public function push(View $view): void
    {
        $this->stack[] = $view;
        $this->mount($view);
    }

    /**
     * Pop the top view. Popping the last view is a no-op: callers decide whether to quit.
     */
    public function pop(): void
    {
        if (\count($this->stack) <= 1) {
            return;
        }
        $this->leave(array_pop($this->stack));
        $this->mount($this->top());
    }

    /**
     * Pop views until $view is the top (no-op when it is not on the stack).
     */
    public function popTo(View $view): void
    {
        if (!\in_array($view, $this->stack, true)) {
            return;
        }
        $popped = false;
        while ($this->top() !== $view) {
            $this->leave($this->top());
            array_pop($this->stack);
            $popped = true;
        }
        if ($popped) {
            $this->mount($view);
        }
    }

    public function replace(View $view): void
    {
        if ([] !== $this->stack) {
            $this->leave(array_pop($this->stack));
        }
        $this->push($view);
    }

    public function top(): View
    {
        return $this->stack[\count($this->stack) - 1] ?? throw new \LogicException('The navigation stack is empty.');
    }

    public function depth(): int
    {
        return \count($this->stack);
    }

    /**
     * Whether $view is currently on the stack (top or below).
     */
    public function contains(View $view): bool
    {
        return \in_array($view, $this->stack, true);
    }

    private function mount(View $view): void
    {
        $this->tui->clear();
        $this->tui->getFocusManager()->clear();
        $this->tui->add($view);
        $view->activate();
        $this->tui->requestRender();
    }

    private function leave(View $view): void
    {
        $this->async->cancel($view);
        $view->deactivate();
    }
}

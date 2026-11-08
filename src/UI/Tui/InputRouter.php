<?php

declare(strict_types=1);

namespace App\UI\Tui;

use App\UI\Tui\Input\Keys;
use App\UI\Tui\Input\MouseEvent;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Input\KeyParser;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\EditorWidget;
use Symfony\Component\Tui\Widget\InputWidget;

/**
 * The single global {@see InputEvent} listener.
 *
 * Order: key releases and mouse reports are consumed here; Ctrl+C quits; when a
 * text widget has focus, input goes straight to it (then FocusManager Tab
 * cycling); otherwise the top view chain gets {@see View\View::onAnyKey()} and
 * its named shortcuts (outermost view first); then `?` opens help. Anything not
 * consumed flows on to the FocusManager and the focused widget.
 */
final class InputRouter
{
    public function __construct(
        private readonly TuiApp $app,
        private readonly HitTester $hitTester,
        private readonly KeyParser $parser,
    ) {
        $app->tui()->addListener(function (InputEvent $event): void {
            if ($this->route($event->getData())) {
                $event->stopPropagation();
                $this->app->tui()->requestRender();
            }
        }, 100);
    }

    private function route(string $data): bool
    {
        if (Keys::isRelease($data)) {
            return true;
        }

        $mouse = MouseEvent::tryParse($data);
        if (null !== $mouse) {
            $this->mouse($mouse);

            return true;
        }
        if (MouseEvent::isReport($data)) {
            return true;
        }

        if ($this->parser->matches($data, 'ctrl+c')) {
            $this->app->quit();

            return true;
        }

        $tui = $this->app->tui();
        $focused = $tui->getFocus();
        if ($focused instanceof InputWidget || $focused instanceof EditorWidget) {
            return false;
        }

        $nav = $this->app->navigator();
        $top = $nav->top();
        if ($top->onAnyKey($data)) {
            return true;
        }

        for ($view = $top; null !== $view; $view = $view->activeChild()) {
            $shortcuts = $view->shortcuts();
            if ([] === $shortcuts) {
                continue;
            }
            $bindings = new Keybindings($shortcuts, $this->parser);
            foreach (array_keys($shortcuts) as $action) {
                if ($bindings->matches($data, $action) && $view->handleShortcut($action)) {
                    return true;
                }
            }
        }

        if ('?' === $data || '?' === $this->parser->decodePrintable($data)) {
            $nav->push($this->app->views()->help($top));

            return true;
        }

        return false;
    }

    private function mouse(MouseEvent $event): void
    {
        if (0 !== $event->wheel) {
            $this->app->tui()->handleInput($event->wheel < 0 ? "\e[A" : "\e[B");

            return;
        }
        if (!$event->press) {
            return;
        }
        // Symbol navigation: Ctrl/Alt/Cmd+left-click, or a middle/right click,
        // mirror the Go ctrl+click go-to-definition gesture.
        $nav = (MouseEvent::BUTTON_LEFT === $event->button && ($event->ctrl || $event->alt || $event->shift))
            || MouseEvent::BUTTON_MIDDLE === $event->button
            || MouseEvent::BUTTON_RIGHT === $event->button;
        if (MouseEvent::BUTTON_LEFT === $event->button || $nav) {
            $this->hitTester->click($event->x, $event->y, $nav);
        }
    }
}

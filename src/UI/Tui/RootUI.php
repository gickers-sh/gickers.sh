<?php

declare(strict_types=1);

namespace App\UI\Tui;

use App\Identity\Domain\Model\User;
use App\UI\Tui\Input\Keys;
use App\UI\Tui\Page\Page;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Tui;

/**
 * Owns the {@see Tui} instance and the active {@see Page}, and routes all raw
 * input through a single persistent {@see InputEvent} listener. Pages are
 * keyboard-only and never register their own dispatcher listeners, so switching
 * pages (clear + mount) cannot leak listeners.
 *
 * Replaces the deleted `RootUI` that composed Bubble Tea-style components.
 */
final class RootUI implements TuiHost
{
    private ?Page $active = null;

    public function __construct(
        private readonly Tui $tui,
        private readonly TuiPageFactory $factory,
        private readonly ?User $actor,
        /** When false (soft:browse of a local repo) there is no feed, so "back" quits. */
        private readonly bool $selectionAvailable = true,
    ) {
        $this->tui->addListener(function (InputEvent $event): void {
            if (null === $this->active) {
                return;
            }
            if (Keys::isRelease($event->getData())) {
                $event->stopPropagation();

                return;
            }
            if ($this->active->handleKey($event->getData())) {
                $this->active->sync();
                $event->stopPropagation();
                $this->tui->requestRender();
            }
        });
    }
    public function start(Page $page): void
    {
        $terminal = $this->tui->getTerminal();
        // Enable alternate screen buffer (\e[?1049h), SGR mouse mode (\e[?1006h), and button event mouse tracking (\e[?1002h).
        // This prevents the terminal emulator window from scrolling its own history buffer on mouse wheel,
        // routing wheel events directly to the application as ANSI mouse sequences.
        $terminal->write("\x1b[?1049h\x1b[?1000h\x1b[?1002h\x1b[?1006h");
        $this->activate($page);
    }

    private function activate(Page $page): void
    {
        $this->tui->clear();
        $this->tui->getFocusManager()->clear();
        $this->active = $page;
        $page->mount($this);
        $page->sync();
        $this->tui->requestRender();
    }

    public function tui(): Tui
    {
        return $this->tui;
    }

    public function actor(): ?User
    {
        return $this->actor;
    }

    public function navigateToRepo(string $name): void
    {
        try {
            $this->activate($this->factory->repoPageForName($this->actor, $name));
        } catch (\Throwable) {
            // denyRead / NotFound: the repo is not selectable, stay on the current page.
        }
    }

    public function navigateToSelection(): void
    {
        if ($this->selectionAvailable) {
            $this->activate($this->factory->selectionPage($this->actor));

            return;
        }

        $this->quit();
    }

    public function navigateToAdmin(): void
    {
        $this->activate($this->factory->adminPage($this->actor));
    }

    public function quit(): void
    {
        // Disable mouse tracking and restore standard screen buffer.
        $this->tui->getTerminal()->write("\x1b[?1006l\x1b[?1002l\x1b[?1000l\x1b[?1049l");
        $this->tui->stop();
    }
}

<?php

declare(strict_types=1);

namespace App\UI\Tui;

use App\Identity\Domain\Model\User;
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
            if ($this->active->handleKey($event->getData())) {
                $this->active->sync();
                $event->stopPropagation();
                $this->tui->requestRender();
            }
        });
    }

    public function start(Page $page): void
    {
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
        $this->tui->stop();
    }
}

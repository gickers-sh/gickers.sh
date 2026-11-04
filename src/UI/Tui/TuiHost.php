<?php

declare(strict_types=1);

namespace App\UI\Tui;

use App\Identity\Domain\Model\User;
use Symfony\Component\Tui\Tui;

/**
 * Navigation and lifecycle surface a {@see \App\UI\Tui\Page\Page} uses to drive
 * the shell: swap the active page, reach the terminal, and quit.
 */
interface TuiHost
{
    public function tui(): Tui;

    public function actor(): ?User;

    /**
     * Open a repository (by stored name) applying denyRead; stays put on failure.
     */
    public function navigateToRepo(string $name): void;

    public function navigateToSelection(): void;

    public function navigateToAdmin(): void;

    public function quit(): void;
}

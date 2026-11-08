<?php

declare(strict_types=1);

namespace App\UI\Tui;

use App\Identity\Domain\Model\User;

/**
 * Per-connection TUI session state shared by every view.
 */
final class Session
{
    /** One-shot message shown by the next view that renders a flash line. */
    public ?string $flash = null;

    public function __construct(
        public readonly ?User $actor,
        /** When false (soft:browse of a local repo) there is no feed, so "back" at the root quits. */
        public readonly bool $selectionAvailable,
    ) {
    }
}

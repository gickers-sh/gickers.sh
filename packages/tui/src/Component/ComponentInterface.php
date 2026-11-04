<?php

declare(strict_types=1);

namespace CodesSh\Tui\Component;

use CodesSh\Tui\Event\InputEvent;

interface ComponentInterface
{
    public function init(): void;

    /**
     * Handles an input event. Returns 'quit' to exit the runner, or null to continue.
     */
    public function update(InputEvent $event): ?string;

    /**
     * Renders the component view for the given terminal width and height.
     */
    public function view(int $width, int $height): string;
}

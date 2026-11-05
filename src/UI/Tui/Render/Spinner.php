<?php

declare(strict_types=1);

namespace App\UI\Tui\Render;

use Symfony\Component\Tui\Widget\LoaderWidget;

/**
 * Animated terminal spinner backed by {@see LoaderWidget} ('dots' style: ⠋ ⠙ ⠹ ⠸ ⠼ ⠴ ⠦ ⠧ ⠇ ⠏)
 * and formatted with the TokyoNight {@see Theme} palette.
 */
final class Spinner
{
    private readonly LoaderWidget $loader;

    public function __construct(string $message = 'Loading...')
    {
        $this->loader = new LoaderWidget($message);
        $this->loader->setSpinner('dots');
        $this->loader->start();
    }

    public function advance(?float $deltaSeconds = null): void
    {
        if (!$this->loader->isRunning()) {
            $this->loader->start();
        }
        $this->loader->tick($deltaSeconds);
    }

    public function frame(): string
    {
        return $this->loader->getSpinnerFrame();
    }

    public function format(string $message = 'Loading...'): string
    {
        return Theme::style($this->loader->getSpinnerFrame(), Theme::HIGHLIGHT, bold: true)
            .' '
            .Theme::style($message, Theme::MUTED);
    }
}

<?php

declare(strict_types=1);

namespace App\Tui\Component;

use App\Tui\Event\InputEvent;
use App\Tui\Event\KeyMsg;
use App\Tui\Event\MouseMsg;
use App\Tui\Style\Ansi;

final class ViewportComponent implements ComponentInterface
{
    /** @var string[] */
    private array $lines = [];
    private int $yOffset = 0;
    private int $width = 80;
    private int $height = 24;

    public function __construct(string $content = '')
    {
        $this->setContent($content);
    }

    public function init(): void
    {
        $this->yOffset = 0;
    }

    public function setContent(string $content): void
    {
        $this->lines = explode("\n", str_replace("\r\n", "\n", $content));
        $this->clampOffset();
    }

    public function setSize(int $width, int $height): void
    {
        $this->width = max(1, $width);
        $this->height = max(1, $height);
        $this->clampOffset();
    }

    public function getYOffset(): int
    {
        return $this->yOffset;
    }

    public function setYOffset(int $offset): void
    {
        $this->yOffset = $offset;
        $this->clampOffset();
    }

    public function gotoTop(): void
    {
        $this->yOffset = 0;
    }

    public function gotoBottom(): void
    {
        $this->yOffset = max(0, count($this->lines) - $this->height);
    }

    public function getScrollPercentage(): int
    {
        $maxOffset = max(0, count($this->lines) - $this->height);
        if ($maxOffset === 0) {
            return 100;
        }

        return (int) round(($this->yOffset / $maxOffset) * 100);
    }

    public function update(InputEvent $event): ?string
    {
        if ($event instanceof KeyMsg) {
            if ($event->is('j') || $event->is(KeyMsg::DOWN)) {
                $this->yOffset++;
                $this->clampOffset();
                return null;
            }
            if ($event->is('k') || $event->is(KeyMsg::UP)) {
                $this->yOffset--;
                $this->clampOffset();
                return null;
            }
            if ($event->is('d') || $event->is(KeyMsg::PAGE_DOWN) || $event->is('ctrl+d')) {
                $this->yOffset += max(1, (int) ($this->height / 2));
                $this->clampOffset();
                return null;
            }
            if ($event->is('u') || $event->is(KeyMsg::PAGE_UP) || $event->is('ctrl+u')) {
                $this->yOffset -= max(1, (int) ($this->height / 2));
                $this->clampOffset();
                return null;
            }
            if ($event->is('g') || $event->is(KeyMsg::HOME)) {
                $this->gotoTop();
                return null;
            }
            if ($event->is('G') || $event->is(KeyMsg::END)) {
                $this->gotoBottom();
                return null;
            }
        } elseif ($event instanceof MouseMsg) {
            if ($event->isWheelDown()) {
                $this->yOffset += 3;
                $this->clampOffset();
                return null;
            }
            if ($event->isWheelUp()) {
                $this->yOffset -= 3;
                $this->clampOffset();
                return null;
            }
        }

        return null;
    }

    public function view(int $width, int $height): string
    {
        $this->setSize($width, $height);
        $totalLines = count($this->lines);

        $visible = array_slice($this->lines, $this->yOffset, $this->height);
        $outputLines = [];

        foreach ($visible as $line) {
            $w = Ansi::width($line);
            if ($w > $this->width) {
                $outputLines[] = Ansi::truncate($line, $this->width);
            } else {
                $outputLines[] = $line . str_repeat(' ', max(0, $this->width - $w));
            }
        }

        // Fill remaining height with empty padded lines
        while (count($outputLines) < $this->height) {
            $outputLines[] = str_repeat(' ', $this->width);
        }

        return implode("\n", $outputLines);
    }

    private function clampOffset(): void
    {
        $maxOffset = max(0, count($this->lines) - $this->height);
        if ($this->yOffset > $maxOffset) {
            $this->yOffset = $maxOffset;
        }
        if ($this->yOffset < 0) {
            $this->yOffset = 0;
        }
    }
}

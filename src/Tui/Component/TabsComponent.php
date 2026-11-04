<?php

declare(strict_types=1);

namespace App\Tui\Component;

use App\Tui\Event\InputEvent;
use App\Tui\Event\KeyMsg;
use App\Tui\Event\MouseMsg;
use App\Tui\Style\Ansi;
use App\Tui\Style\Style;
use App\Tui\Style\TokyoNight;
use App\Tui\Style\ZoneManager;

final class TabsComponent implements ComponentInterface
{
    /** @var string[] */
    private array $tabs;
    private int $activeTab = 0;
    private bool $useDot = false;
    private string $separator = "│";

    /**
     * @param string[] $tabs
     */
    public function __construct(array $tabs = [], bool $useDot = false)
    {
        $this->tabs = array_values($tabs);
        $this->useDot = $useDot;
    }

    public function setUseDot(bool $useDot): void
    {
        $this->useDot = $useDot;
    }

    public function setSeparator(string $separator): void
    {
        $this->separator = $separator;
    }
    public function init(): void
    {
        $this->activeTab = 0;
    }

    public function getActiveTab(): int
    {
        return $this->activeTab;
    }

    public function getActiveTabName(): string
    {
        return $this->tabs[$this->activeTab] ?? '';
    }

    public function setActiveTab(int $index): void
    {
        if ($index >= 0 && $index < count($this->tabs)) {
            $this->activeTab = $index;
        }
    }

    /**
     * @param string[] $tabs
     */
    public function setTabs(array $tabs): void
    {
        $this->tabs = array_values($tabs);
        if ($this->activeTab >= count($this->tabs)) {
            $this->activeTab = max(0, count($this->tabs) - 1);
        }
    }

    public function update(InputEvent $event): ?string
    {
        if (empty($this->tabs)) {
            return null;
        }

        $count = count($this->tabs);

        if ($event instanceof KeyMsg) {
            if ($event->is(KeyMsg::TAB)) {
                $this->activeTab = ($this->activeTab + 1) % $count;
                return null;
            }
            if ($event->is(KeyMsg::BACKTAB)) {
                $this->activeTab = ($this->activeTab - 1 + $count) % $count;
                return null;
            }
            if (ctype_digit($event->key)) {
                $num = (int) $event->key;
                if ($num >= 1 && $num <= $count) {
                    $this->activeTab = $num - 1;
                    return null;
                }
            }
        } elseif ($event instanceof MouseMsg && $event->isLeftClick()) {
            foreach ($this->tabs as $idx => $tab) {
                if (ZoneManager::inBounds("tab_{$idx}", $event)) {
                    $this->activeTab = $idx;
                    return null;
                }
            }
        }

        return null;
    }

    public function view(int $width, int $height): string
    {
        if (empty($this->tabs)) {
            return '';
        }

        $reset = TokyoNight::reset();
        $cyan = TokyoNight::hexToAnsi(TokyoNight::ACTIVE_TAB);
        $normalFg = TokyoNight::hexToAnsi(TokyoNight::FG);
        $sepColor = TokyoNight::hexToAnsi(TokyoNight::TAB_SEPARATOR);
        $sep = ' ' . $sepColor . $this->separator . $reset . ' ';
        $sepWidth = Ansi::width($sep);

        // Render each tab
        $renderedTabs = [];
        foreach ($this->tabs as $idx => $tab) {
            $isActive = ($idx === $this->activeTab);
            if ($this->useDot) {
                if ($isActive) {
                    $prefix = $cyan . "• " . $reset;
                    $tabText = $cyan . $tab . $reset;
                } else {
                    $prefix = "  ";
                    $tabText = $normalFg . $tab . $reset;
                }
                $content = $prefix . $tabText;
            } else {
                if ($isActive) {
                    // Underlined cyan
                    $content = "\x1b[4m" . $cyan . $tab . $reset;
                } else {
                    $content = $normalFg . $tab . $reset;
                }
            }

            $marked = ZoneManager::mark("tab_{$idx}", $content);
            $renderedTabs[] = [
                'rendered' => $marked,
                'width' => Ansi::width($content),
            ];
        }

        // Check if all tabs fit
        $totalW = 0;
        $numTabs = count($renderedTabs);
        foreach ($renderedTabs as $i => $rt) {
            $totalW += $rt['width'];
            if ($i < $numTabs - 1) {
                $totalW += $this->useDot ? 2 : $sepWidth;
            }
        }

        if ($width <= 0 || $totalW <= $width) {
            $out = '';
            foreach ($renderedTabs as $i => $rt) {
                $out .= $rt['rendered'];
                if ($i < $numTabs - 1) {
                    $out .= $this->useDot ? '  ' : $sep;
                }
            }
            return $out;
        }

        // Scrollable window
        $leftArrow = '‹ ';
        $rightArrow = ' ›';
        $leftW = Ansi::width($leftArrow);
        $rightW = Ansi::width($rightArrow);

        $spanWidth = function (int $start, int $end) use ($renderedTabs, $sepWidth, $leftW, $rightW, $numTabs): int {
            $w = 0;
            if ($start > 0) {
                $w += $leftW;
            }
            for ($i = $start; $i <= $end; $i++) {
                $w += $renderedTabs[$i]['width'];
                if ($i > $start) {
                    $w += $this->useDot ? 2 : $sepWidth;
                }
            }
            if ($end < $numTabs - 1) {
                $w += $rightW;
            }
            return $w;
        };

        $active = max(0, min($numTabs - 1, $this->activeTab));
        $start = $active;
        $end = $active;

        while (true) {
            $canLeft = $start > 0 && $spanWidth($start - 1, $end) <= $width;
            $canRight = $end < $numTabs - 1 && $spanWidth($start, $end + 1) <= $width;

            if (!$canLeft && !$canRight) {
                break;
            }

            if ($canRight && ($end - $active <= $active - $start || !$canLeft)) {
                $end++;
            } elseif ($canLeft) {
                $start--;
            } elseif ($canRight) {
                $end++;
            }
        }

        $out = '';
        if ($start > 0) {
            $out .= $normalFg . $leftArrow . $reset;
        }
        for ($i = $start; $i <= $end; $i++) {
            $out .= $renderedTabs[$i]['rendered'];
            if ($i < $end) {
                $out .= $this->useDot ? '  ' : $sep;
            }
        }
        if ($end < $numTabs - 1) {
            $out .= $normalFg . $rightArrow . $reset;
        }

        return $out;
    }
}

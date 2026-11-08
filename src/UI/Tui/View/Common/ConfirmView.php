<?php

declare(strict_types=1);

namespace App\UI\Tui\View\Common;

use App\UI\Tui\Format\Chrome;
use App\UI\Tui\Navigator;
use App\UI\Tui\Render\Theme;
use App\UI\Tui\View\View;
use App\UI\Tui\Widget\LinesWidget;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Style\Align;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\VerticalAlign;

/**
 * A centered yes/no confirmation box. y/Enter pops then runs $onYes; n/Esc pops.
 */
final class ConfirmView extends View
{
    /**
     * @param list<string>     $body  box lines (ANSI allowed)
     * @param \Closure(): void $onYes
     */
    public function __construct(
        private readonly Navigator $navigator,
        private readonly string $boxTitle,
        array $body,
        private readonly \Closure $onYes,
        string $hint = 'y/enter: confirm · n/esc: cancel',
        ?string $borderColor = Theme::SELECTOR,
    ) {
        $this->setStyle(new Style(align: Align::Center, verticalAlign: VerticalAlign::Center));
        $this->expandVertically(true);
        $lines = array_merge([''], $body, ['', '  '.Theme::style($hint, Theme::MUTED, italic: true)]);
        $this->add(new LinesWidget(static function (int $cols) use ($lines, $boxTitle, $borderColor): array {
            $inner = 0;
            foreach ($lines as $l) {
                $inner = max($inner, \App\UI\Tui\Format\Ansi::width($l) + 2);
            }

            return Chrome::box($boxTitle, $lines, min(max(40, $inner), max(20, $cols - 4)), $borderColor);
        }));
    }

    public function title(): string
    {
        return $this->boxTitle;
    }

    public function shortcuts(): array
    {
        return [
            'yes' => ['y', Key::ENTER],
            'no' => ['n', Key::ESCAPE, 'q'],
        ];
    }

    public function handleShortcut(string $action): bool
    {
        if ('yes' === $action) {
            $this->navigator->pop();
            ($this->onYes)();

            return true;
        }
        if ('no' === $action) {
            $this->navigator->pop();

            return true;
        }

        return false;
    }

    public function keyHints(): array
    {
        return [['y', 'confirm'], ['n', 'cancel']];
    }
}

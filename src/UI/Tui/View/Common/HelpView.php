<?php

declare(strict_types=1);

namespace App\UI\Tui\View\Common;

use App\UI\Tui\Format\Chrome;
use App\UI\Tui\Navigator;
use App\UI\Tui\View\View;
use App\UI\Tui\Widget\LinesWidget;

/**
 * Full help grid for the view below it ({@see View::helpColumns()}).
 * Any key closes it (Ctrl+C still quits through the router).
 */
final class HelpView extends View
{
    public function __construct(
        private readonly Navigator $navigator,
        View $for,
    ) {
        $this->expandVertically(true);
        $columns = $for->helpColumns();
        $this->add(new LinesWidget(static fn (int $cols): array => array_merge(
            [''],
            array_map(static fn (string $l): string => '  '.$l, Chrome::helpGrid($columns, max(1, $cols - 4))),
        )));
    }

    public function title(): string
    {
        return 'Help';
    }

    public function onAnyKey(string $data): bool
    {
        $this->navigator->pop();

        return true;
    }
}

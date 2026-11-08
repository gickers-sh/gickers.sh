<?php

declare(strict_types=1);

namespace App\UI\Tui\View\Selection;

use App\Identity\Domain\Model\User;
use App\UI\Tui\Format\Chrome;
use App\UI\Tui\Render\Theme as RenderTheme;
use App\UI\Tui\View\View;
use App\UI\Tui\Widget\LinesWidget;

final class ProfilePane extends View
{
    public function __construct(private readonly ?User $actor)
    {
        $this->expandVertically(true);
        $this->add(new LinesWidget(function (int $cols): array {
            if (null === $this->actor) {
                return Chrome::messageBody('Not signed in (anonymous session).', $cols);
            }

            $isAdmin = $this->actor->isAdmin();
            $keysCount = \count($this->actor->publicKeys());

            return [
                '',
                '  '.RenderTheme::style('Username  ', RenderTheme::MUTED).RenderTheme::style($this->actor->username(), RenderTheme::FG),
                '  '.RenderTheme::style('Role      ', RenderTheme::MUTED).RenderTheme::style($isAdmin ? 'administrator' : 'user', RenderTheme::FG),
                '  '.RenderTheme::style('Keys      ', RenderTheme::MUTED).RenderTheme::style((string) $keysCount, RenderTheme::FG),
            ];
        }));
    }

    public function title(): string
    {
        return 'Profile';
    }

    public function keyHints(): array
    {
        return [
            ['↑↓', 'navigate'],
            ['tab', 'section'],
            ['q', 'quit'],
            ['?', 'toggle help'],
        ];
    }
}

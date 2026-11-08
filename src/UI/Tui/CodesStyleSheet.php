<?php

declare(strict_types=1);

namespace App\UI\Tui;

use App\UI\Tui\Render\Theme;
use Symfony\Component\Tui\Style\Border;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\LoaderWidget;
use Symfony\Component\Tui\Widget\SettingsListWidget;

/**
 * The single TUI stylesheet, built from the TokyoNight {@see Theme} palette.
 *
 * The canvas keeps the terminal's own background (as the previous UI did).
 * Multi-column rows are pre-formatted ANSI ({@see Format\Rows}); these rules
 * style the framework widgets and the chrome classes.
 */
final class CodesStyleSheet
{
    public static function create(): StyleSheet
    {
        return new StyleSheet([
            '.chrome' => new Style(padding: new Padding(0, 2, 0, 2)),
            '.header' => new Style(color: Theme::FG, bold: true),
            '.rule' => new Style(color: Theme::BORDER),
            '.tabbar' => new Style(direction: Direction::Horizontal, gap: 1),
            '.tab' => new Style(color: Theme::MUTED),
            '.tab-active' => new Style(color: Theme::CYAN, bold: true, underline: true),
            '.status' => new Style(background: Theme::BORDER, color: Theme::FG_DIM),
            '.help' => new Style(color: Theme::MUTED),
            '.flash' => new Style(color: Theme::SELECTOR, bold: true),
            '.modal' => new Style(padding: Padding::all(1), border: Border::all(1, 'rounded', Theme::BORDER2)),
            '.field-label' => new Style(color: Theme::MUTED),
            '.error' => new Style(color: Theme::SELECTOR),
            '.indent' => new Style(padding: new Padding(0, 0, 0, 2)),
            '.hsplit' => new Style(direction: Direction::Horizontal, gap: 1),
            '.flex' => new Style(flex: 1),
            '.hidden' => new Style(hidden: true),
            InputWidget::class.':focus' => new Style(color: Theme::FG),
            LoaderWidget::class.'::spinner' => new Style(color: Theme::HIGHLIGHT, bold: true),
            LoaderWidget::class.'::message' => new Style(color: Theme::MUTED),
            SettingsListWidget::class.'::label-selected' => new Style(color: Theme::SELECTOR, bold: true),
            SettingsListWidget::class.'::value' => new Style(color: Theme::MUTED),
            SettingsListWidget::class.'::value-selected' => new Style(color: Theme::CYAN),
            SettingsListWidget::class.'::hint' => new Style(color: Theme::MUTED),
        ]);
    }
}

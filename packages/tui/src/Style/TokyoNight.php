<?php

declare(strict_types=1);

namespace CodesSh\Tui\Style;

final class TokyoNight
{
    public const BG = '#1a1b26';
    public const FG = '#c0caf5';
    public const ACTIVE_BORDER = '#7aa2f7';
    public const INACTIVE_BORDER = '#3b4261';
    public const SELECTION = '#364a82';
    public const STATUS_BAR = '#1f2335';
    public const SUCCESS = '#9ece6a';
    public const ERROR = '#f7768e';
    public const WARNING = '#e0af68';
    public const MUTED = '#565f89';
    public const CYAN = '#7dcfff';
    public const MAGENTA = '#bb9af7';
    public const WHITE = '#ffffff';

    // Soft Serve / codes.sh TokyoNight theme constants (matching Go styles)
    public const SERVER_NAME_BG = '#3d59a1';
    public const SERVER_NAME_FG = '#c0caf5';
    public const ACTIVE_TAB = '#7dcfff';
    public const ACTIVE_TAB_DOT = '#7dcfff';
    public const TAB_SEPARATOR = '#3b4261';
    public const ITEM_ACTIVE_BAR = '#bb9af7';
    public const ITEM_ACTIVE_TITLE = '#7aa2f7';
    public const ITEM_ACTIVE_TITLE_REPO = '#9d7cd8';
    public const ITEM_NORMAL_TITLE_REPO = '#a9b1d6';
    public const ITEM_ACTIVE_DESC = '#a9b1d6';
    public const ITEM_NORMAL_DESC = '#565f89';
    public const ITEM_ACTIVE_CMD = '#f7768e';
    public const ITEM_NORMAL_CMD = '#bb9af7';
    public const ITEM_UPDATED = '#565f89';
    public const HELP_KEY = '#565f89';
    public const HELP_VALUE = '#545c7e';
    public const HELP_DIVIDER = '#3b4261';
    public const NO_CONTENT_FG = '#565f89';

    // Tree & Repo styles
    public const TREE_DIR_FG = '#7dcfff';
    public const TREE_FILE_ACTIVE = '#bb9af7';
    public const TREE_MODE_NORMAL = '#565f89';
    public const TREE_MODE_ACTIVE = '#9d7cd8';
    public const TREE_SIZE_NORMAL = '#565f89';
    public const TREE_SIZE_ACTIVE = '#9d7cd8';
    public const TREE_SELECTOR = '#f7768e';
    public const REPO_HEADER_BORDER = '#292e42';
    public const REPO_HEADER_NAME = '#7aa2f7';
    public const REPO_HEADER_REPO_NAME = '#9d7cd8';
    public const REPO_HEADER_DESC = '#a9b1d6';
    public const URL_STYLE = '#bb9af7';

    // Status bar styles (matching Go styles.go: StatusBarKey, StatusBarValue, StatusBarInfo, StatusBarBranch, StatusBarHelp)
    public const STATUS_BAR_KEY_BG = '#bb9af7';
    public const STATUS_BAR_KEY_FG = '#1a1b26';
    public const STATUS_BAR_VALUE_BG = '#292e42';
    public const STATUS_BAR_VALUE_FG = '#565f89';
    public const STATUS_BAR_INFO_BG = '#7aa2f7';
    public const STATUS_BAR_INFO_FG = '#1a1b26';
    public const STATUS_BAR_BRANCH_BG = '#7aa2f7';
    public const STATUS_BAR_BRANCH_FG = '#1a1b26';
    public const STATUS_BAR_HELP_BG = '#3b4261';
    public const STATUS_BAR_HELP_FG = '#565f89';

    /**
     * Converts a hex color (#RRGGBB or #RGB) into ANSI TrueColor escape sequence.
     */
    public static function hexToAnsi(string $hex, bool $bg = false): string
    {
        $hex = ltrim($hex, '#');
        if (3 === strlen($hex)) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (6 !== strlen($hex) || !ctype_xdigit($hex)) {
            return '';
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        $mode = $bg ? '48;2;' : '38;2;';

        return "\x1b[{$mode}{$r};{$g};{$b}m";
    }

    public static function reset(): string
    {
        return "\x1b[0m";
    }
}

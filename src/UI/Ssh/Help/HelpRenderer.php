<?php

declare(strict_types=1);

namespace App\UI\Ssh\Help;

use App\UI\Tui\Render\Theme;

/**
 * Renders a {@see CommandSpec} for SSH CLI help output.
 *
 * In raw mode ($decorated = false), reproduces upstream Soft Serve's cobra
 * help output (custom UsageTemplate + pflag FlagUsages) byte-for-byte.
 *
 * In decorated mode ($decorated = true), applies TokyoNight Theme styling
 * while maintaining exact column alignments and spacing.
 */
final class HelpRenderer
{
    /**
     * @param string $sshCommand the "ssh [-p PORT] HOST" prefix, derived from the SSH public URL
     */
    public function render(CommandSpec $command, string $sshCommand, bool $decorated = false): string
    {
        $out = '';
        $desc = '' !== $command->long ? $command->long : $command->short;
        if ('' !== $desc) {
            $descText = rtrim($desc);
            if ($decorated) {
                $styledLines = [];
                $currentSection = null;
                foreach (explode("\n", $descText) as $line) {
                    if (1 === preg_match('/^(Scope|Arguments|Notes|Permissions|Output|Flags):\s*$/', $line, $m)) {
                        $currentSection = $m[1];
                        $styledLines[] = Theme::style($line, Theme::BLUE, bold: true);
                    } elseif ('Arguments' === $currentSection && 1 === preg_match('/^  ([A-Za-z0-9_\-\[\]|<>\.]+)(\s{2,})(.*)$/', $line, $m)) {
                        $styledLines[] = '  ' . Theme::style($m[1], Theme::TEAL, bold: true) . $m[2] . self::styleDescriptionText($m[3], Theme::FG_DIM);
                    } elseif ('Flags' === $currentSection && 1 === preg_match('/^  (-[a-zA-Z], )?(--[a-z0-9-]+)(\s+[a-z]+)?(\s{2,})(.*)$/', $line, $m)) {
                        $flagStr = '  ';
                        if ('' !== $m[1]) {
                            $flagStr .= Theme::style('-' . trim($m[1], " -,\t"), Theme::ORANGE, bold: true) . Theme::style(', ', Theme::MUTED);
                        }
                        $flagStr .= Theme::style($m[2], Theme::ORANGE, bold: true);
                        if ('' !== $m[3]) {
                            $flagStr .= ' ' . Theme::style(trim($m[3]), Theme::TEAL, italic: true);
                        }
                        $styledLines[] = $flagStr . $m[4] . self::styleDescriptionText($m[5], Theme::FG_DIM);
                    } elseif ('Scope' === $currentSection && str_starts_with($line, '  ')) {
                        $styledLines[] = self::styleScopeLine($line);
                    } elseif ('' !== trim($line)) {
                        $styledLines[] = self::styleDescriptionText($line, Theme::FG);
                    } else {
                        $currentSection = null;
                        $styledLines[] = $line;
                    }
                }
                $out .= implode("\n", $styledLines) . "\n\n";
            } else {
                $out .= $descText . "\n\n";
            }
        }

        if ($command->runnable || $command->hasChildren()) {
            $out .= $this->usageString($command, $sshCommand, $decorated);
        }

        return $out;
    }

    private function usageString(CommandSpec $command, string $sshCommand, bool $decorated): string
    {
        $out = $decorated ? Theme::style('Usage:', Theme::BLUE, bold: true) : 'Usage:';

        if ($command->runnable) {
            $out .= "\n  " . $this->useLine($command, $decorated);
        }

        if ($command->hasChildren()) {
            $cmdPath = $command->commandPath();
            if ($decorated) {
                $out .= "\n  " . CliHighlighter::highlightLine($sshCommand . $cmdPath) . ' ' . Theme::style('[command]', Theme::MUTED, italic: true);
            } else {
                $out .= "\n  " . $sshCommand . $cmdPath . ' [command]';
            }
        }

        if ([] !== $command->aliases) {
            $aliasesHeading = $decorated ? Theme::style('Aliases:', Theme::BLUE, bold: true) : 'Aliases:';
            if ($decorated) {
                $styledAliases = implode(
                    Theme::style(', ', Theme::MUTED),
                    array_map(static fn(string $a): string => Theme::style($a, Theme::CYAN, italic: true), $command->aliases)
                );
                $out .= "\n\n" . $aliasesHeading . "\n  " . Theme::style($command->name, Theme::CYAN, bold: true) . Theme::style(', ', Theme::MUTED) . $styledAliases;
            } else {
                $aliasList = implode(', ', $command->aliases);
                $out .= "\n\n" . $aliasesHeading . "\n  " . $command->name . ', ' . $aliasList;
            }
        }
        if ('' !== $command->example) {
            $examplesHeading = $decorated ? Theme::style('Examples:', Theme::BLUE, bold: true) : 'Examples:';
            $exampleBody = str_replace('{ssh}', $sshCommand, ltrim(rtrim($command->example), "\r\n"));
            if ($decorated) {
                $styledLines = [];
                foreach (explode("\n", $exampleBody) as $line) {
                    $styledLines[] = CliHighlighter::highlightLine($line);
                }
                $out .= "\n\n" . $examplesHeading . "\n" . implode("\n", $styledLines);
            } else {
                $out .= "\n\n" . $examplesHeading . "\n" . $exampleBody;
            }
        }

        if ($command->hasChildren()) {
            $padding = $command->namePadding();
            $children = $command->visibleChildren();
            usort($children, static fn(CommandSpec $a, CommandSpec $b): int => strcmp($a->name, $b->name));

            $commandsHeading = $decorated ? Theme::style('Available Commands:', Theme::BLUE, bold: true) : 'Available Commands:';
            $out .= "\n\n" . $commandsHeading;
            foreach ($children as $child) {
                if ($decorated) {
                    $out .= "\n  " . Theme::style($child->name, Theme::CYAN, bold: true)
                        . str_repeat(' ', $padding - strlen($child->name)) . ' '
                        . self::styleDescriptionText($child->short, Theme::FG_DIM);
                } else {
                    $out .= "\n  " . $this->rpad($child->name, $padding) . ' ' . $child->short;
                }
            }
        }

        $flagsHeading = $decorated ? Theme::style('Flags:', Theme::BLUE, bold: true) : 'Flags:';
        $out .= "\n\n" . $flagsHeading . "\n" . $this->flagUsages($command, $decorated);

        if ($command->hasChildren()) {
            $cmdPath = $command->commandPath();
            if ($decorated) {
                $out .= "\n\n" . Theme::style('Use "', Theme::MUTED)
                    . CliHighlighter::highlightLine($sshCommand . $cmdPath)
                    . ' ' . Theme::style('[command]', Theme::MUTED, italic: true)
                    . ' ' . Theme::style('--help', Theme::ORANGE, bold: true)
                    . Theme::style('" for more information about a command.', Theme::MUTED);
            } else {
                $out .= "\n\nUse \"" . $sshCommand . $cmdPath . ' [command] --help" for more information about a command.';
            }
        }

        return $out . "\n";
    }

    private function useLine(CommandSpec $command, bool $decorated): string
    {
        $parentPath = null !== $command->parent ? $command->parent->commandPath() : '';
        $line = $parentPath . ' ' . $command->use;

        // Every command has at least the implicit --help flag.
        if (!str_contains($line, '[flags]')) {
            $line .= ' [flags]';
        }

        if (!$decorated) {
            return $line;
        }

        return (string) preg_replace_callback(
            '/\S+/',
            static function (array $m): string {
                $word = $m[0];
                if ('[flags]' === $word || '[command]' === $word) {
                    return Theme::style($word, Theme::MUTED, italic: true);
                }
                if (str_starts_with($word, '[') && str_ends_with($word, ']')) {
                    if (str_contains($word, 'true|false')) {
                        return Theme::style($word, Theme::HIGHLIGHT, italic: true);
                    }

                    return Theme::style($word, Theme::TEAL, italic: true);
                }
                if (str_starts_with($word, '<') && str_ends_with($word, '>')) {
                    return Theme::style($word, Theme::TEAL, italic: true);
                }
                // Uppercase positional argument placeholders (e.g. REPOSITORY, USERNAME, KEY, SHA)
                if (1 === preg_match('/^[A-Z0-9_\-]+$/', $word)) {
                    return Theme::style($word, Theme::TEAL, bold: true);
                }

                // Command verbs and subcommands
                return Theme::style($word, Theme::CYAN, bold: true);
            },
            $line
        );
    }

    /**
     * Reproduces pflag's FlagUsagesWrapped(0) followed by trimTrailingWhitespaces.
     */
    private function flagUsages(CommandSpec $command, bool $decorated): string
    {
        $flags = [...$command->flags, $this->helpFlag($command)];
        usort($flags, static fn(FlagSpec $a, FlagSpec $b): int => strcmp($a->name, $b->name));

        $prefixes = [];
        $usages = [];
        $maxlen = 0;
        foreach ($flags as $flag) {
            if (null !== $flag->shorthand && '' !== $flag->shorthand) {
                $prefix = '  -' . $flag->shorthand . ', --' . $flag->name;
            } else {
                $prefix = '      --' . $flag->name;
            }
            if ('' !== $flag->varname) {
                $prefix .= ' ' . $flag->varname;
            }

            $usage = $flag->usage;
            if (null !== $flag->defaultText) {
                $usage .= ' (default ' . $flag->defaultText . ')';
            }

            $prefixes[] = $prefix;
            $usages[] = $usage;

            // pflag compares len(prefix)+1 (the \x00 marker) against maxlen.
            $len = strlen($prefix) + 1;
            if ($len > $maxlen) {
                $maxlen = $len;
            }
        }

        $lines = [];
        foreach ($prefixes as $i => $prefix) {
            $sidx = strlen($prefix);
            // Fprintln joins "prefix", spacing, usage with single spaces:
            // prefix + " " + repeat(" ", maxlen - sidx) + " " + usage
            $spacing = str_repeat(' ', $maxlen - $sidx);

            if (!$decorated) {
                $lines[] = $prefix . ' ' . $spacing . ' ' . $usages[$i];
                continue;
            }

            $flag = $flags[$i];
            if (null !== $flag->shorthand && '' !== $flag->shorthand) {
                $flagStr = '  ' . Theme::style('-' . $flag->shorthand, Theme::ORANGE, bold: true) . Theme::style(', ', Theme::MUTED) . Theme::style('--' . $flag->name, Theme::ORANGE, bold: true);
            } else {
                $flagStr = '      ' . Theme::style('--' . $flag->name, Theme::ORANGE, bold: true);
            }
            if ('' !== $flag->varname) {
                $flagStr .= ' ' . Theme::style($flag->varname, Theme::TEAL, italic: true);
            }

            $descText = $flag->usage;
            $styledUsage = self::styleDescriptionText($descText, Theme::FG_DIM);
            if (null !== $flag->defaultText) {
                $styledUsage .= ' ' . Theme::style('(default ' . $flag->defaultText . ')', Theme::MUTED, italic: true);
            }

            $lines[] = $flagStr . ' ' . $spacing . ' ' . $styledUsage;
        }

        return rtrim(implode("\n", $lines));
    }

    private function helpFlag(CommandSpec $command): FlagSpec
    {
        $usage = null === $command->parent
            ? 'help for this command'
            : 'help for ' . $command->name;

        return new FlagSpec('h', 'help', '', $usage, null);
    }

    private function rpad(string $value, int $padding): string
    {
        return str_pad($value, $padding);
    }

    /**
     * Styles inline elements (backticks, quotes, flags, URLs) within a description string.
     */
    public static function styleDescriptionText(string $text, string $baseFg = Theme::FG): string
    {
        $parts = preg_split(
            '/(`[^`]+`|\x27[^\x27]+\x27|"[^"]+"|\bhttps?:\/\/\S+|\B--[a-z0-9-]+|\B-[a-zA-Z]\b)/',
            $text,
            -1,
            \PREG_SPLIT_DELIM_CAPTURE
        );

        if (false === $parts || [] === $parts) {
            return Theme::style($text, $baseFg);
        }

        $out = '';
        foreach ($parts as $part) {
            if ('' === $part) {
                continue;
            }
            if (str_starts_with($part, '`') && str_ends_with($part, '`')) {
                $out .= Theme::style($part, Theme::HIGHLIGHT, bold: true);
            } elseif ((str_starts_with($part, "'") && str_ends_with($part, "'")) || (str_starts_with($part, '"') && str_ends_with($part, '"'))) {
                $out .= Theme::style($part, Theme::GREEN);
            } elseif (1 === preg_match('#^https?://#', $part)) {
                $out .= Theme::style($part, Theme::BLUE, underline: true);
            } elseif (str_starts_with($part, '-')) {
                $out .= Theme::style($part, Theme::ORANGE, bold: true);
            } else {
                $out .= Theme::style($part, $baseFg);
            }
        }

        return $out;
    }

    /**
     * Styles access/permission scope lines with TokyoNight semantic badges.
     */
    public static function styleScopeLine(string $line): string
    {
        $trimmed = ltrim($line);
        $indent = substr($line, 0, strlen($line) - strlen($trimmed));

        $parts = preg_split(
            '/(Server Admin|Repository Owner|Repository Admin|Authenticated user|Authenticated|Public|Read access|Unregistered SSH keys)/',
            $trimmed,
            -1,
            \PREG_SPLIT_DELIM_CAPTURE
        );

        if (false === $parts || [] === $parts) {
            return $indent . Theme::style($trimmed, Theme::FG_DIM);
        }

        $out = $indent;
        foreach ($parts as $part) {
            if ('' === $part) {
                continue;
            }
            if (in_array($part, ['Server Admin', 'Repository Owner'], true)) {
                $out .= Theme::style($part, Theme::HIGHLIGHT, bold: true);
            } elseif (in_array($part, ['Repository Admin', 'Authenticated user', 'Authenticated'], true)) {
                $out .= Theme::style($part, Theme::CYAN, bold: true);
            } elseif ('Public' === $part) {
                $out .= Theme::style($part, Theme::GREEN, bold: true);
            } elseif ('Read access' === $part) {
                $out .= Theme::style($part, Theme::TEAL);
            } elseif ('Unregistered SSH keys' === $part) {
                $out .= Theme::style($part, Theme::MUTED2, bold: true);
            } else {
                $out .= self::styleDescriptionText($part, Theme::FG_DIM);
            }
        }

        return $out;
    }

    /**
     * Derives the "ssh [-p PORT] [USER@]HOST" prefix from the SSH public URL, exactly
     * like upstream Soft Serve's UsageFunc.
     */
    public static function sshCommandFromPublicUrl(string $publicUrl, ?string $user = null): string
    {
        $hostname = 'localhost';
        $port = '23231';

        $parsed = parse_url($publicUrl);
        if (false !== $parsed && isset($parsed['host'])) {
            $hostname = $parsed['host'];
            $port = isset($parsed['port']) ? (string) $parsed['port'] : '';
        }

        $sshCommand = 'ssh';
        if ('' !== $port && '22' !== $port) {
            $sshCommand .= ' -p ' . $port;
        }

        $target = null !== $user && '' !== trim($user) ? trim($user) . '@' . $hostname : $hostname;

        return $sshCommand . ' ' . $target;
    }
}

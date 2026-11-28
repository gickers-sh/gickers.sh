<?php

declare(strict_types=1);

namespace App\UI\Ssh\Help;

use App\Shared\Infrastructure\Config\ServerSettings;
use App\UI\Tui\Render\Theme;

/**
 * Renders a {@see CommandSpec} for SSH CLI help output.
 *
 * In raw mode ($decorated = false), reproduces plain text help output.
 *
 * In decorated mode ($decorated = true), applies TokyoNight Theme styling
 * while maintaining exact column alignments and spacing.
 */
final class HelpRenderer
{
    public function __construct(
        private readonly ?\Twig\Environment $twig = null,
        private readonly ?\App\UI\Tui\Render\TwigTuiRenderer $twigTui = null,
        private readonly ?ServerSettings $serverSettings = null,
    ) {
    }

    public function renderTemplate(CommandSpec $command, string $sshCommand, int $width = 80): string
    {
        $wikiSlug = $command->resolveWikiSlug();
        $httpBase = self::httpBaseFromSsh($sshCommand, $this->serverSettings?->httpPublicUrl);

        if (null !== $this->twigTui) {
            return $this->twigTui->render('@cli/help/command.html.twig', [
                'command' => $command,
                'ssh_command' => $sshCommand,
                'flags' => $command->flags,
                'wiki_slug' => $wikiSlug,
                'http_base' => $httpBase,
            ], $width);
        }

        if (null !== $this->twig) {
            return (string) $this->twig->render('@cli/help/command.html.twig', [
                'command' => $command,
                'ssh_command' => $sshCommand,
                'flags' => $command->flags,
                'wiki_slug' => $wikiSlug,
                'http_base' => $httpBase,
            ]);
        }

        return $this->render($command, $sshCommand, decorated: true);
    }

    /**
     * @param string $sshCommand the "ssh [-p PORT] HOST" prefix, derived from the SSH public URL
     */
    public function render(CommandSpec $command, string $sshCommand, bool $decorated = false, ?string $httpPublicUrl = null): string
    {
        $desc = '' !== $command->long ? $command->long : $command->short;
        $descriptionLines = [];
        $sections = [];
        $currentSection = null;

        if ('' !== $desc) {
            foreach (explode("\n", rtrim($desc)) as $line) {
                if (1 === preg_match('/^(Scope|Arguments|Notes|Permissions|Output|Flags):\s*$/', $line, $m)) {
                    $currentSection = $m[1];
                    $sections[$currentSection] = [];
                    continue;
                }
                if (null === $currentSection) {
                    $descriptionLines[] = $line;
                } else {
                    $sections[$currentSection][] = $line;
                }
            }
        }

        $blocks = [];

        // 1. Description
        $descText = trim(implode("\n", $descriptionLines));
        if ('' !== $descText) {
            if ($decorated) {
                $styledDesc = [];
                foreach (explode("\n", $descText) as $l) {
                    $styledDesc[] = self::styleDescriptionText($l, Theme::FG);
                }
                $blocks[] = implode("\n", $styledDesc);
            } else {
                $blocks[] = $descText;
            }
        }

        // 2. Usage
        if ($command->runnable || $command->hasChildren()) {
            $usageBlock = $decorated ? Theme::style('Usage:', Theme::BLUE, bold: true) : 'Usage:';
            if ($command->runnable) {
                $usageBlock .= "\n  " . $this->useLine($command, $decorated);
            }
            if ($command->hasChildren()) {
                $cmdPath = $command->commandPath();
                if ($decorated) {
                    $usageBlock .= "\n  " . CliHighlighter::highlightLine($sshCommand . $cmdPath) . ' ' . Theme::style('[command]', Theme::MUTED, italic: true);
                } else {
                    $usageBlock .= "\n  " . $sshCommand . $cmdPath . ' [command]';
                }
            }
            if ([] !== $command->aliases) {
                $aliasesHeading = $decorated ? Theme::style('Aliases:', Theme::BLUE, bold: true) : 'Aliases:';
                if ($decorated) {
                    $styledAliases = implode(
                        Theme::style(', ', Theme::MUTED),
                        array_map(static fn(string $a): string => Theme::style($a, Theme::CYAN, italic: true), $command->aliases)
                    );
                    $usageBlock .= "\n\n" . $aliasesHeading . "\n  " . Theme::style($command->name, Theme::CYAN, bold: true) . Theme::style(', ', Theme::MUTED) . $styledAliases;
                } else {
                    $aliasList = implode(', ', $command->aliases);
                    $usageBlock .= "\n\n" . $aliasesHeading . "\n  " . $command->name . ', ' . $aliasList;
                }
            }
            $blocks[] = $usageBlock;
        }

        // Available Commands for group commands
        if ($command->hasChildren()) {
            $padding = $command->namePadding();
            $children = $command->visibleChildren();
            usort($children, static fn(CommandSpec $a, CommandSpec $b): int => strcmp($a->name, $b->name));

            $commandsHeading = $decorated ? Theme::style('Available Commands:', Theme::BLUE, bold: true) : 'Available Commands:';
            $cmdsBlock = $commandsHeading;
            foreach ($children as $child) {
                if ($decorated) {
                    $cmdsBlock .= "\n  " . Theme::style($child->name, Theme::CYAN, bold: true)
                        . str_repeat(' ', $padding - strlen($child->name)) . ' '
                        . self::styleDescriptionText($child->short, Theme::FG_DIM);
                } else {
                    $cmdsBlock .= "\n  " . $this->rpad($child->name, $padding) . ' ' . $child->short;
                }
            }
            $blocks[] = $cmdsBlock;
        }

        // 3. Arguments
        if (isset($sections['Arguments']) && [] !== $sections['Arguments']) {
            $argsLines = [];
            foreach ($sections['Arguments'] as $line) {
                if ($decorated && 1 === preg_match('/^  ([A-Za-z0-9_\-\[\]|<>\.]+)(\s{2,})(.*)$/', $line, $m)) {
                    $argsLines[] = '  ' . Theme::style($m[1], Theme::TEAL, bold: true) . $m[2] . self::styleDescriptionText($m[3], Theme::FG_DIM);
                } else {
                    $argsLines[] = $line;
                }
            }
            $trimmedArgs = rtrim(implode("\n", $argsLines));
            if ('' !== $trimmedArgs) {
                $argsHeading = $decorated ? Theme::style('Arguments:', Theme::BLUE, bold: true) : 'Arguments:';
                $blocks[] = $argsHeading . "\n" . $trimmedArgs;
            }
        }

        // 4. Flags (single block, flags from description discarded in favor of authoritative flags)
        if ($command->runnable || $command->hasChildren()) {
            $flagsHeading = $decorated ? Theme::style('Flags:', Theme::BLUE, bold: true) : 'Flags:';
            $blocks[] = $flagsHeading . "\n" . $this->flagUsages($command, $decorated);
        }

        // 5. Scope
        if (isset($sections['Scope']) && [] !== $sections['Scope']) {
            $scopeLines = [];
            foreach ($sections['Scope'] as $line) {
                if ($decorated && str_starts_with($line, '  ')) {
                    $scopeLines[] = self::styleScopeLine($line);
                } else {
                    $scopeLines[] = $line;
                }
            }
            $trimmedScope = rtrim(implode("\n", $scopeLines));
            if ('' !== $trimmedScope) {
                $scopeHeading = $decorated ? Theme::style('Scope:', Theme::BLUE, bold: true) : 'Scope:';
                $blocks[] = $scopeHeading . "\n" . $trimmedScope;
            }
        }

        // Notes, Permissions, Output
        foreach (['Notes', 'Permissions', 'Output'] as $extra) {
            if (isset($sections[$extra]) && [] !== $sections[$extra]) {
                $extraLines = [];
                foreach ($sections[$extra] as $line) {
                    if ($decorated) {
                        $extraLines[] = self::styleDescriptionText($line, Theme::FG_DIM);
                    } else {
                        $extraLines[] = $line;
                    }
                }
                $trimmedExtra = rtrim(implode("\n", $extraLines));
                if ('' !== $trimmedExtra) {
                    $heading = $decorated ? Theme::style($extra . ':', Theme::BLUE, bold: true) : $extra . ':';
                    $blocks[] = $heading . "\n" . $trimmedExtra;
                }
            }
        }

        // 6. Examples
        if ('' !== $command->example) {
            $examplesHeading = $decorated ? Theme::style('Examples:', Theme::BLUE, bold: true) : 'Examples:';
            $exampleBody = self::interpolateSsh($command->example, $sshCommand, $httpPublicUrl ?? $this->serverSettings?->httpPublicUrl);
            if ($decorated) {
                $styledLines = [];
                foreach (explode("\n", $exampleBody) as $line) {
                    $styledLines[] = CliHighlighter::highlightLine($line);
                }
                $blocks[] = $examplesHeading . "\n" . implode("\n", $styledLines);
            } else {
                $blocks[] = $examplesHeading . "\n" . $exampleBody;
            }
        }

        // 7. Wiki guide links (Web / CLI / TUI)
        $wikiSlug = $command->resolveWikiSlug();
        if ('' !== $wikiSlug) {
            $httpBase = self::httpBaseFromSsh($sshCommand, $httpPublicUrl ?? $this->serverSettings?->httpPublicUrl);
            $webUrl = $httpBase . '/wiki/' . $wikiSlug;
            $cliCmd = $sshCommand . ' wiki show ' . $wikiSlug;
            $wikiHeading = $decorated ? Theme::style('Wiki:', Theme::BLUE, bold: true) : 'Wiki:';

            if ($decorated) {
                $blocks[] = $wikiHeading
                    . "\n  " . Theme::style('Web:', Theme::TEAL, bold: true) . '  ' . Theme::style($webUrl, Theme::BLUE, underline: true)
                    . "\n  " . Theme::style('CLI:', Theme::TEAL, bold: true) . '  ' . CliHighlighter::highlightLine($cliCmd)
                    . "\n  " . Theme::style('TUI:', Theme::TEAL, bold: true) . '  ' . CliHighlighter::highlightLine($sshCommand)
                    . Theme::style(' → ', Theme::MUTED) . Theme::style('Wiki', Theme::CYAN, bold: true)
                    . Theme::style(' → ', Theme::MUTED) . Theme::style($wikiSlug, Theme::GREEN);
            } else {
                $blocks[] = $wikiHeading
                    . "\n  Web:  " . $webUrl
                    . "\n  CLI:  " . $cliCmd
                    . "\n  TUI:  " . $sshCommand . ' → Wiki → ' . $wikiSlug;
            }
        }

        // Footer for group commands
        if ($command->hasChildren()) {
            $cmdPath = $command->commandPath();
            if ($decorated) {
                $blocks[] = Theme::style('Use "', Theme::MUTED)
                    . CliHighlighter::highlightLine($sshCommand . $cmdPath)
                    . ' ' . Theme::style('[command]', Theme::MUTED, italic: true)
                    . ' ' . Theme::style('--help', Theme::ORANGE, bold: true)
                    . Theme::style('" for more information about a command.', Theme::MUTED);
            } else {
                $blocks[] = 'Use "' . $sshCommand . $cmdPath . ' [command] --help" for more information about a command.';
            }
        }

        return implode("\n\n", $blocks) . "\n";
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
     * Interpolates SSH and HTTP command placeholders in example text:
     * - `{ssh}` -> Full SSH command for the current session (e.g. `ssh -p 2222 user@host` or `ssh host`)
     * - `{ssh-base}` -> SSH command without user prefix (e.g. `ssh -p 2222 host` or `ssh host`)
     * - `{ssh:<user>}` -> SSH command targeting a custom user (e.g. `ssh -p 2222 <user>@host` or `ssh <user>@host`)
     * - `{http}` -> Public HTTP base URL (e.g. `https://gickers.sh`)
     */
    public static function interpolateSsh(string $text, string $sshCommand, ?string $httpPublicUrl = null): string
    {
        $trimmed = trim($sshCommand);
        if (1 === preg_match('/^(.*?\bssh(?:\s+-[a-zA-Z0-9]+(?:\s+[^\s]+)?)*)\s+(?:([^@\s]+)@)?([^\s]+)$/', $trimmed, $matches)) {
            $prefix = $matches[1];
            $host = $matches[3];
            $baseSsh = $prefix . ' ' . $host;
        } else {
            $prefix = 'ssh';
            $host = 'gickers.sh';
            $baseSsh = $sshCommand;
        }

        $httpBase = self::httpBaseFromSsh($sshCommand, $httpPublicUrl);

        $result = str_replace(
            ['{ssh-base}', '{ssh}', '{http}'],
            [$baseSsh, $sshCommand, $httpBase],
            ltrim(rtrim($text), "\r\n")
        );

        return (string) preg_replace_callback('/\{ssh:([^}]+)\}/', static function (array $m) use ($prefix, $host): string {
            return $prefix . ' ' . $m[1] . '@' . $host;
        }, $result);
    }

    /**
     * Resolves the HTTP base URL from explicit settings or falls back cleanly from the SSH target.
     */
    public static function httpBaseFromSsh(string $sshCommand, ?string $httpPublicUrl = null): string
    {
        $explicit = trim((string) $httpPublicUrl);
        if ('' !== $explicit) {
            return rtrim($explicit, '/');
        }

        $trimmed = trim($sshCommand);
        $host = 'gickers.sh';
        if (1 === preg_match('/^(?:.*?\bssh(?:\s+-[a-zA-Z0-9]+(?:\s+[^\s]+)?)*)\s+(?:[^@\s]+@)?([^\s]+)$/', $trimmed, $matches)) {
            $host = $matches[1];
        }

        if ('localhost' === $host || '127.0.0.1' === $host) {
            return 'http://' . $host . ':23232';
        }

        return 'https://' . $host;
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

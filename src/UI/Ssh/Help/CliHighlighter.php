<?php

declare(strict_types=1);

namespace App\UI\Ssh\Help;

use App\UI\Tui\Render\Theme;

/**
 * Token-aware syntax highlighter for CLI and SSH command examples.
 *
 * Formats commands with TokyoNight palette styles, distinct token colors,
 * bold subcommands/binaries, italic comments, and underlined URLs.
 * Stripping ANSI codes from the output always returns the exact input string.
 */
final class CliHighlighter
{
    private const SUBCOMMANDS = [
        'add',
        'add-pubkey',
        'allow-keyless',
        'allow-signup',
        'anon-access',
        'blob',
        'branch',
        'clone',
        'collab',
        'commit',
        'create',
        'default',
        'delete',
        'description',
        'diff',
        'fork',
        'grep',
        'help',
        'hidden',
        'import',
        'info',
        'list',
        'mirror',
        'password',
        'private',
        'project-name',
        'protect',
        'pubkey',
        'readme',
        'recover',
        'remove',
        'remove-pubkey',
        'rename',
        'repo',
        'set-admin',
        'set-username',
        'settings',
        'signatures',
        'signup',
        'tag',
        'token',
        'tree',
        'unprotect',
        'user',
        'webhook',
    ];

    private const BINARIES = [
        'ssh',
        'git',
        'curl',
    ];

    private const ROLES_AND_FORMATS = [
        'read-write',
        'read-only',
        'admin-access',
        'no-access',
        'json',
    ];

    /**
     * Highlights a single line of CLI example or shell code.
     */
    public static function highlightLine(string $line): string
    {
        $trimmed = ltrim($line);
        if ('' === $trimmed) {
            return $line;
        }

        $indent = substr($line, 0, strlen($line) - strlen($trimmed));

        // Comments: soft muted color in italic
        if (str_starts_with($trimmed, '#')) {
            return $indent . Theme::style($trimmed, Theme::MUTED, italic: true);
        }

        // Tokenize command line preserving exact offsets, spaces, and quoted strings
        preg_match_all(
            '/"(?:[^"\\\\]|\\\\.)*"|\x27(?:[^\x27\\\\]|\\\\.)*\x27|>>|>|\\||&&|;|\S+/',
            $trimmed,
            $matches,
            \PREG_OFFSET_CAPTURE
        );

        $out = $indent;
        $lastOffset = 0;

        /** @var list<array{0: string, 1: int}> $tokenMatches */
        $tokenMatches = $matches[0];
        foreach ($tokenMatches as $match) {
            $token = $match[0];
            $offset = $match[1];

            if ($offset > $lastOffset) {
                $out .= substr($trimmed, $lastOffset, $offset - $lastOffset);
            }

            $out .= self::highlightToken($token);
            $lastOffset = $offset + strlen($token);
        }

        if ($lastOffset < strlen($trimmed)) {
            $out .= substr($trimmed, $lastOffset);
        }

        return $out;
    }

    /**
     * Categorizes and styles an individual CLI token.
     */
    public static function highlightToken(string $token): string
    {
        // Quoted strings: "string" or 'string'
        if ((str_starts_with($token, '"') && str_ends_with($token, '"'))
            || (str_starts_with($token, "'") && str_ends_with($token, "'"))) {
            return Theme::style($token, Theme::GREEN);
        }

        // Shell redirection and pipe operators
        if (in_array($token, ['>', '>>', '|', '&&', ';'], true)) {
            return Theme::style($token, Theme::HASH, bold: true);
        }

        // Flags: -f, --flag, --flag=value
        if (str_starts_with($token, '-')) {
            if (str_contains($token, '=')) {
                [$flag, $val] = explode('=', $token, 2);

                return Theme::style($flag, Theme::ORANGE, bold: true) . '=' . self::highlightToken($val);
            }

            return Theme::style($token, Theme::ORANGE, bold: true);
        }

        // URLs and endpoints
        if (1 === preg_match('#^(https?|git|ssh)://#', $token)) {
            return Theme::style($token, Theme::BLUE, underline: true);
        }

        // Booleans
        if (in_array($token, ['true', 'false'], true)) {
            return Theme::style($token, Theme::HIGHLIGHT, bold: true);
        }

        // Numbers & durations (e.g. 2223, 42, 30d, 2h, 1y)
        if (1 === preg_match('/^[0-9]+([a-z]+)?$/i', $token)) {
            return Theme::style($token, Theme::ORANGE);
        }

        // Executables / Binaries
        if (in_array($token, self::BINARIES, true)) {
            return Theme::style($token, Theme::BLUE, bold: true);
        }

        // Known subcommands
        if (in_array($token, self::SUBCOMMANDS, true)) {
            return Theme::style($token, Theme::CYAN, bold: true);
        }

        // Git revisions, tags, HEAD, and commit SHAs
        if (1 === preg_match('/^(HEAD(~[0-9]+)?|v[0-9].*|[0-9a-f]{7,40})$/i', $token)) {
            return Theme::style($token, Theme::HASH, bold: true);
        }

        // Known roles / content-types / formats
        if (in_array($token, self::ROLES_AND_FORMATS, true)) {
            return Theme::style($token, Theme::TEAL);
        }

        // Default arguments / identifiers / hostnames / paths
        return Theme::style($token, Theme::FG);
    }
}

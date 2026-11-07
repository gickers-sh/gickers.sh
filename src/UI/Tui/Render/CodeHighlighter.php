<?php

declare(strict_types=1);

namespace App\UI\Tui\Render;

use Symfony\Component\Process\Process;
use Tempest\Highlight\Highlighter;
use Tempest\Highlight\TerminalTheme;
use Tempest\Highlight\Themes\EscapesTerminalTheme;
use Tempest\Highlight\Tokens\TokenType;
use Tempest\Highlight\Tokens\TokenTypeEnum;

/**
 * Language-aware syntax highlighting for the Files tab code viewer, the PHP
 * counterpart of Go Soft Serve's chroma-based `code.renderFile`.
 *
 * The whole file is tokenised at once (so multi-line comments, strings and
 * heredocs keep their context) and then split back into lines; every output
 * line is self-contained: it re-opens the colour active at its start and
 * ends with a reset.
 */
final class CodeHighlighter implements TerminalTheme
{
    use EscapesTerminalTheme;

    private const TAB_WIDTH = 4;

    private const BY_EXTENSION = [
        'php' => 'php',
        'phtml' => 'php',
        'inc' => 'php',
        'js' => 'js',
        'mjs' => 'js',
        'cjs' => 'js',
        'ts' => 'ts',
        'mts' => 'ts',
        'cts' => 'ts',
        'jsx' => 'jsx',
        'tsx' => 'tsx',
        'go' => 'go',
        'rs' => 'rust',
        'py' => 'python',
        'rb' => 'ruby',
        'java' => 'java',
        'kt' => 'kotlin',
        'kts' => 'kotlin',
        'swift' => 'swift',
        'c' => 'c',
        'h' => 'c',
        'cpp' => 'cpp',
        'cc' => 'cpp',
        'cxx' => 'cpp',
        'hpp' => 'cpp',
        'hh' => 'cpp',
        'cs' => 'csharp',
        'gd' => 'gdscript',
        'css' => 'css',
        'scss' => 'scss',
        'html' => 'html',
        'htm' => 'html',
        'vue' => 'vue',
        'svelte' => 'svelte',
        'twig' => 'twig',
        'xml' => 'xml',
        'svg' => 'xml',
        'xsd' => 'xml',
        'xlf' => 'xml',
        'json' => 'json',
        'yaml' => 'yaml',
        'yml' => 'yaml',
        'neon' => 'yaml',
        'toml' => 'toml',
        'ini' => 'ini',
        'cfg' => 'ini',
        'sql' => 'sql',
        'graphql' => 'graphql',
        'gql' => 'graphql',
        'sh' => 'bash',
        'bash' => 'bash',
        'zsh' => 'bash',
        'md' => 'markdown',
        'markdown' => 'markdown',
        'diff' => 'diff',
        'patch' => 'diff',
        'tf' => 'terraform',
        'env' => 'dotenv',
    ];

    private const BY_BASENAME = [
        'dockerfile' => 'dockerfile',
        'containerfile' => 'dockerfile',
        'makefile' => 'bash',
        '.env' => 'dotenv',
        '.htaccess' => 'apache',
    ];

    private ?Highlighter $highlighter = null;

    /**
     * Highlighter language name for $path, or null when unsupported.
     */
    public static function languageFor(string $path): ?string
    {
        $base = strtolower(basename($path));
        if (str_ends_with($base, '.blade.php')) {
            return 'blade';
        }
        if (isset(self::BY_BASENAME[$base])) {
            return self::BY_BASENAME[$base];
        }
        if (str_starts_with($base, '.env.') || str_starts_with($base, 'dockerfile.')) {
            return str_starts_with($base, '.env.') ? 'dotenv' : 'dockerfile';
        }
        $ext = pathinfo($base, \PATHINFO_EXTENSION);

        return self::BY_EXTENSION[$ext] ?? null;
    }

    /**
     * Highlights $content as ANSI lines, one per source line (same split as
     * the caller's raw lines). Returns null when the language is unsupported
     * or the highlighter fails, letting the caller show plain text.
     *
     * @return list<string>|null
     */
    public function highlightLines(string $path, string $content): ?array
    {
        $language = $this->languageFor($path);
        if (null === $language) {
            return null;
        }

        $source = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", str_repeat(' ', self::TAB_WIDTH)], rtrim($content, "\r\n"));
        try {
            $ansi = ($this->highlighter ??= new Highlighter($this))->parse($source, $language);
        } catch (\Throwable) {
            return null;
        }

        // Token escapes are properly nested in the output (before = colour,
        // after = reset). Track that nesting so a closing inner token restores
        // its parent's colour and each line re-opens the colour active at its start.
        $lines = [];
        $stack = [];
        foreach (explode("\n", $ansi) as $line) {
            $out = end($stack) ?: '';
            foreach (preg_split('/(\e\[[0-9;]*m)/', $line, -1, \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                if ("\e" !== $part[0]) {
                    $out .= $part;
                } elseif (Theme::RESET === $part) {
                    array_pop($stack);
                    $out .= Theme::RESET.(end($stack) ?: '');
                } else {
                    $stack[] = $part;
                    $out .= $part;
                }
            }
            $lines[] = $out.Theme::RESET;
        }

        return $lines;
    }

    /**
     * Starts a non-blocking PHP subprocess that runs {@see highlightLines()} and outputs
     * a JSON array of ANSI lines (or null), avoiding blocking the TUI event loop on large files.
     */
    public function startHighlightProcess(string $path, string $content): Process
    {
        $autoload = \dirname(__DIR__, 4).'/vendor/autoload.php';
        $script = 'require $argv[1];'
            .'$payload = json_decode(stream_get_contents(STDIN), true);'
            .'if (!is_array($payload)) { exit(1); }'
            .'$h = new \App\UI\Tui\Render\CodeHighlighter();'
            .'$res = $h->highlightLines((string) $payload["path"], (string) $payload["content"]);'
            .'fwrite(STDOUT, json_encode($res, JSON_THROW_ON_ERROR));';

        $payload = json_encode([
            'path' => $path,
            'content' => $content,
        ], \JSON_THROW_ON_ERROR);

        $process = new Process([\PHP_BINARY, '-r', $script, $autoload]);
        $process->setInput($payload);
        $process->start();

        return $process;
    }

    public function before(TokenType $tokenType): string
    {
        return Theme::fg($this->colorFor($tokenType));
    }

    public function after(TokenType $tokenType): string
    {
        return Theme::RESET;
    }

    /**
     * TokyoNight palette, aligned with the TUI chrome ({@see Theme}).
     */
    private function colorFor(TokenType $tokenType): string
    {
        return match ($tokenType) {
            TokenTypeEnum::KEYWORD => Theme::HIGHLIGHT,
            TokenTypeEnum::OPERATOR => Theme::CYAN,
            TokenTypeEnum::TYPE => '#2ac3de',
            TokenTypeEnum::VALUE => Theme::GREEN,
            TokenTypeEnum::VARIABLE => Theme::FG,
            TokenTypeEnum::PROPERTY => '#73daca',
            TokenTypeEnum::ATTRIBUTE => Theme::HASH,
            TokenTypeEnum::GENERIC => Theme::BLUE,
            TokenTypeEnum::NUMBER => '#ff9e64',
            TokenTypeEnum::LITERAL => '#ff9e64',
            TokenTypeEnum::COMMENT => Theme::MUTED,
            default => match ($tokenType->getValue()) {
                'diff-addition' => Theme::GREEN,
                'diff-deletion' => Theme::SELECTOR,
                'diff-hunk-header' => Theme::HIGHLIGHT,
                'diff-file-header' => Theme::CYAN,
                'diff-special' => Theme::HASH,
                'diff-metadata', 'diff-context' => Theme::MUTED,
                default => Theme::FG,
            },
        };
    }
}

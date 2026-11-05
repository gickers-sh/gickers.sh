<?php

declare(strict_types=1);

namespace App\UI\Tui\Render;

use App\UI\Tui\Input\Keys;

/**
 * In-TUI single/multi-line text buffer with cursor navigation and reverse-video rendering.
 */
final class InlineEditor
{
    /** @var list<string> */
    private array $lines = [''];
    private int $line = 0;
    private int $col = 0;
    private bool $dirty = false;

    private ?MarkdownRenderer $previewRenderer = null;
    private string $previewFilename = '';
    private string $previewMode = 'off';
    private int $previewScroll = 0;
    private int $lastRows = 24;
    private int $lastFullLineCount = 0;
    /** @var array<string, string> rendered cache keyed by "width\n<content>" */
    private array $previewCache = [];

    public static function isCtrlS(string $seq): bool
    {
        return "\x13" === $seq || "\e[115;5u" === $seq || "\e[83;5u" === $seq;
    }

    /**
     * Enable Markdown live preview: inactive lines are rendered through the
     * given snippet renderer while the cursor line stays raw source. Preview is
     * turned on by default when the file is Markdown and a renderer is present.
     */
    public function enableMarkdownPreview(?MarkdownRenderer $renderer, string $filename): void
    {
        $this->previewRenderer = $renderer;
        $this->previewFilename = $filename;
        $this->previewMode = self::isMarkdown($filename) && null !== $renderer ? 'inline' : 'off';
        $this->previewScroll = 0;
        $this->previewCache = [];
    }

    /** Whether live preview can apply to the current file (Markdown + renderer). */
    public function previewSupported(): bool
    {
        return null !== $this->previewRenderer && self::isMarkdown($this->previewFilename);
    }

    public function previewMode(): string
    {
        return $this->previewMode;
    }

    public function isLivePreview(): bool
    {
        return $this->previewSupported() && 'inline' === $this->previewMode;
    }

    public function isFullPreview(): bool
    {
        return $this->previewSupported() && 'full' === $this->previewMode;
    }

    /** Cycle preview mode: inline -> full -> off -> inline. No-op when unsupported. */
    public function cyclePreview(): void
    {
        if (!$this->previewSupported()) {
            return;
        }

        $this->previewMode = match ($this->previewMode) {
            'inline' => 'full',
            'full' => 'off',
            default => 'inline',
        };
        $this->previewScroll = 0;
    }

    /** Flip live preview; no-op when unsupported. */
    public function toggleLivePreview(): bool
    {
        $this->cyclePreview();

        return $this->isLivePreview();
    }

    private static function isMarkdown(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, \PATHINFO_EXTENSION));

        return 'md' === $ext || 'markdown' === $ext;
    }

    public function setContent(string $content): void
    {
        if (str_ends_with($content, "\r\n")) {
            $content = substr($content, 0, -2);
        } elseif (str_ends_with($content, "\n") || str_ends_with($content, "\r")) {
            $content = substr($content, 0, -1);
        }

        $parts = preg_split('/\r\n|\r|\n/', $content);
        $this->lines = (false === $parts || [] === $parts || [''] === $parts) ? [''] : array_values($parts);
        $this->line = 0;
        $this->col = 0;
        $this->dirty = true;
        $this->previewScroll = 0;
        $this->previewCache = [];
    }

    public function value(): string
    {
        $content = implode("\n", $this->lines);
        if ('' !== $content && !str_ends_with($content, "\n")) {
            $content .= "\n";
        }

        return $content;
    }

    public function line(): int
    {
        return $this->line;
    }

    public function col(): int
    {
        return $this->col;
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function moveTo(int $line, int $col): void
    {
        $this->line = max(0, min($line, \count($this->lines) - 1));
        $this->col = max(0, min($col, mb_strlen($this->lines[$this->line] ?? '')));
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    public function markClean(): void
    {
        $this->dirty = false;
    }

    public function handleKey(string $seq): bool
    {
        if (self::isCtrlS($seq)) {
            return false;
        }

        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if (Keys::CTRL_P === $tok) {
            $this->cyclePreview();

            return true;
        }

        if (Keys::ESCAPE === $tok) {
            return false;
        }
        if ($this->isFullPreview()) {
            $bodyRows = max(1, $this->lastRows);
            $maxScroll = max(0, $this->lastFullLineCount - $bodyRows);
            $pageStep = max(1, $bodyRows - 2);

            if (Keys::UP === $tok || 'k' === $txt) {
                $this->previewScroll = max(0, $this->previewScroll - 1);
            } elseif (Keys::DOWN === $tok || 'j' === $txt) {
                $this->previewScroll = max(0, min($this->previewScroll + 1, $maxScroll));
            } elseif (Keys::PAGE_UP === $tok || Keys::CTRL_U === $tok || Keys::CTRL_B === $tok || 'b' === $txt) {
                $this->previewScroll = max(0, $this->previewScroll - $pageStep);
            } elseif (Keys::PAGE_DOWN === $tok || Keys::CTRL_D === $tok || Keys::CTRL_F === $tok || Keys::SPACE === $tok || 'f' === $txt) {
                $this->previewScroll = max(0, min($this->previewScroll + $pageStep, $maxScroll));
            } elseif (Keys::HOME === $tok || 'g' === $txt) {
                $this->previewScroll = 0;
            } elseif (Keys::END === $tok || 'G' === $txt) {
                $this->previewScroll = $maxScroll;
            }

            return true;
        }

        if (Keys::ENTER === $tok) {
            $line = $this->lines[$this->line] ?? '';
            $before = mb_substr($line, 0, $this->col);
            $after = mb_substr($line, $this->col);
            $this->lines[$this->line] = $before;
            array_splice($this->lines, $this->line + 1, 0, [$after]);
            $this->dirty = true;
            $this->line++;
            $this->col = 0;

            return true;
        }

        if (Keys::BACKSPACE === $tok) {
            if ($this->col > 0) {
                $line = $this->lines[$this->line] ?? '';
                $this->lines[$this->line] = mb_substr($line, 0, $this->col - 1) . mb_substr($line, $this->col);
                $this->dirty = true;
                $this->col--;
            } elseif ($this->line > 0) {
                $prevLine = $this->lines[$this->line - 1];
                $curLine = $this->lines[$this->line] ?? '';
                $newCol = mb_strlen($prevLine);
                $this->lines[$this->line - 1] = $prevLine . $curLine;
                array_splice($this->lines, $this->line, 1);
                $this->dirty = true;
                $this->line--;
                $this->col = $newCol;
            }

            return true;
        }

        if (Keys::UP === $tok) {
            $this->line = max(0, $this->line - 1);
            $this->col = min($this->col, mb_strlen($this->lines[$this->line] ?? ''));

            return true;
        }

        if (Keys::DOWN === $tok) {
            $last = max(0, \count($this->lines) - 1);
            $this->line = min($last, $this->line + 1);
            $this->col = min($this->col, mb_strlen($this->lines[$this->line] ?? ''));

            return true;
        }

        if (Keys::LEFT === $tok) {
            $this->col = max(0, $this->col - 1);

            return true;
        }

        if (Keys::RIGHT === $tok) {
            $lineLen = mb_strlen($this->lines[$this->line] ?? '');
            $this->col = min($lineLen, $this->col + 1);

            return true;
        }

        if (Keys::HOME === $tok) {
            $this->col = 0;

            return true;
        }

        if (Keys::END === $tok) {
            $this->col = mb_strlen($this->lines[$this->line] ?? '');

            return true;
        }

        if (Keys::PAGE_UP === $tok) {
            $this->line = max(0, $this->line - 10);
            $this->col = min($this->col, mb_strlen($this->lines[$this->line] ?? ''));

            return true;
        }

        if (Keys::PAGE_DOWN === $tok) {
            $last = max(0, \count($this->lines) - 1);
            $this->line = min($last, $this->line + 10);
            $this->col = min($this->col, mb_strlen($this->lines[$this->line] ?? ''));

            return true;
        }

        if (Keys::TAB === $tok) {
            $txt = '    ';
        }

        if (null !== $txt) {
            $line = $this->lines[$this->line] ?? '';
            $before = mb_substr($line, 0, $this->col);
            $after = mb_substr($line, $this->col);
            $this->lines[$this->line] = $before . $txt . $after;
            $this->dirty = true;
            $this->col += mb_strlen($txt);

            return true;
        }

        return true;
    }

    /**
     * @param list<string>|null $highlighted
     *
     * @return list<string>
     */
    public function paint(string $label, int $cols, int $rows, ?array $highlighted = null): array
    {
        if ($this->isFullPreview()) {
            return $this->paintFullPreview($label, $cols, $rows);
        }

        $crumb = "\e[2m" . Screen::fit('  ' . $label, $cols) . "\e[0m";
        $rows = max(1, $rows - 1);
        $this->lastRows = $rows;
        $count = \count($this->lines);
        $gutter = max(3, \strlen((string) $count));
        $codeWidth = max(1, $cols - $gutter - 2);
        $top = $count > $rows ? max(0, min($this->line - intdiv($rows, 2), $count - $rows)) : 0;

        $preview = $this->isLivePreview();
        $styledMap = $preview ? $this->buildInlinePreviewMap($codeWidth, $highlighted) : [];

        $rowsOut = [$crumb];
        for ($r = 0; $r < $rows; ++$r) {
            $idx = $top + $r;
            if ($idx >= $count) {
                $rowsOut[] = Screen::blank($cols);
                continue;
            }

            $line = $this->lines[$idx];
            $highlightedLine = $highlighted[$idx] ?? $line;
            $num = str_pad((string) ($idx + 1), $gutter, ' ', \STR_PAD_LEFT);

            if ($idx === $this->line) {
                $g = "\e[7m" . $num . '▶' . "\e[0m" . ' ';
                $styledLine = $this->renderCursor($highlightedLine, $line, $this->col);
            } else {
                $g = "\e[2m" . $num . '│' . "\e[0m" . ' ';
                $styledLine = $preview
                    ? ($styledMap[$idx] ?? $highlightedLine)
                    : $highlightedLine;
            }

            $rowsOut[] = Screen::fit($g . Screen::truncate($styledLine, $codeWidth), $cols);
        }

        return $rowsOut;
    }

    /**
     * @return list<string>
     */
    private function paintFullPreview(string $label, int $cols, int $rows): array
    {
        $crumb = "\e[2m" . Screen::fit('  ' . $label, $cols) . "\e[0m";
        $bodyRows = max(1, $rows - 1);
        $this->lastRows = $bodyRows;
        $width = max(20, $cols - 2);

        $lines = $this->fullPreviewLines($width);
        $total = \count($lines);
        $this->lastFullLineCount = $total;

        $maxScroll = max(0, $total - $bodyRows);
        $this->previewScroll = max(0, min($this->previewScroll, $maxScroll));

        $rowsOut = [$crumb];
        for ($r = 0; $r < $bodyRows; ++$r) {
            $idx = $this->previewScroll + $r;
            $rowsOut[] = $idx < $total ? Screen::fit($lines[$idx], $cols) : Screen::blank($cols);
        }

        return $rowsOut;
    }

    /**
     * @return list<string>
     */
    private function fullPreviewLines(int $width): array
    {
        $content = $this->value();
        $key = $width . "\n" . $content;
        if (!isset($this->previewCache[$key])) {
            $rendered = '';
            if (null !== $this->previewRenderer && $this->previewRenderer->isAvailable()) {
                try {
                    $rendered = $this->previewRenderer->renderDocument($this->previewFilename, $content, $width);
                } catch (\Throwable) {
                    $rendered = '';
                }
            }
            if ('' === trim($rendered)) {
                $rendered = implode("\n", $this->lines);
            }
            $this->previewCache[$key] = $rendered;
        }

        $raw = rtrim($this->previewCache[$key], "\r\n");
        $lines = preg_split('/\r\n|\r|\n/', $raw);

        return false === $lines || [] === $lines ? [''] : array_values($lines);
    }

    /**
     * @param list<string>|null $highlighted
     *
     * @return array<int, string>
     */
    private function buildInlinePreviewMap(int $codeWidth, ?array $highlighted): array
    {
        $docDefs = '';
        foreach ($this->lines as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '[') && str_contains($trimmed, ']:')) {
                $docDefs .= $trimmed . "\n";
            }
        }

        $kinds = [];
        $inFence = false;
        foreach ($this->lines as $idx => $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '```') || str_starts_with($trimmed, '~~~')) {
                $kinds[$idx] = 'fence';
                $inFence = !$inFence;
                continue;
            }
            if ($inFence) {
                $kinds[$idx] = 'code';
                continue;
            }
            if ('' === trim($line)) {
                $kinds[$idx] = 'blank';
                continue;
            }
            $kinds[$idx] = 'normal';
        }

        $styledMap = [];
        foreach ($this->lines as $idx => $line) {
            if ($idx === $this->line) {
                continue;
            }
            $kind = $kinds[$idx];
            if ('blank' === $kind) {
                $styledMap[$idx] = '';
                continue;
            }
            if ('fence' === $kind) {
                $styledMap[$idx] = "\e[2m" . $line . "\e[0m";
                continue;
            }
            if ('code' === $kind) {
                $styledMap[$idx] = $highlighted[$idx] ?? $line;
                continue;
            }

            $styledMap[$idx] = $this->renderSnippetLine($line, $codeWidth, $docDefs, $highlighted[$idx] ?? null);
        }

        return $styledMap;
    }

    private function renderSnippetLine(string $rawLine, int $width, string $docDefs, ?string $fallback): string
    {
        $key = $width . "\n" . $rawLine;
        if (!isset($this->previewCache[$key])) {
            $rendered = null;
            if (null !== $this->previewRenderer && $this->previewRenderer->isAvailable()) {
                try {
                    $rendered = $this->previewRenderer->renderSnippet($this->previewFilename, $rawLine, $width, $docDefs);
                } catch (\Throwable) {
                    $rendered = null;
                }
            }
            $this->previewCache[$key] = (null !== $rendered && '' !== trim($rendered))
                ? $rendered
                : ($fallback ?? $rawLine);
        }

        return $this->previewCache[$key];
    }

    private function renderCursor(string $highlightedLine, string $rawLine, int $col): string
    {
        $rawLen = mb_strlen($rawLine);
        $col = max(0, min($col, $rawLen));

        if (!str_contains($highlightedLine, "\e")) {
            $before = mb_substr($rawLine, 0, $col);
            $char = $col < $rawLen ? mb_substr($rawLine, $col, 1) : ' ';
            $after = $col < $rawLen ? mb_substr($rawLine, $col + 1) : '';

            return $before . "\e[7m" . $char . "\e[0m" . $after;
        }

        $parts = preg_split('/(\e\[[0-9;]*m)/', $highlightedLine, -1, \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY) ?: [];
        $visibleCol = 0;
        $result = '';
        $cursorPlaced = false;

        foreach ($parts as $part) {
            if (str_starts_with($part, "\e")) {
                $result .= $part;
                continue;
            }

            if ($cursorPlaced) {
                $result .= $part;
                continue;
            }

            $partLen = mb_strlen($part);
            if ($visibleCol + $partLen > $col) {
                $offset = $col - $visibleCol;
                $before = mb_substr($part, 0, $offset);
                $char = mb_substr($part, $offset, 1);
                $after = mb_substr($part, $offset + 1);

                $result .= $before . "\e[7m" . $char . "\e[27m" . $after;
                $cursorPlaced = true;
            } else {
                $visibleCol += $partLen;
                $result .= $part;
            }
        }

        if (!$cursorPlaced) {
            $result .= "\e[7m \e[27m";
        }

        return $result;
    }
}

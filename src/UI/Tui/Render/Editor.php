<?php

declare(strict_types=1);

namespace App\UI\Tui\Render;

use App\UI\Tui\Input\Keys;
use Symfony\Component\Process\Process;

/**
 * In-TUI single/multi-line text buffer with cursor navigation and reverse-video rendering.
 */
final class Editor
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
    private bool $softWrap = true;
    /** @var array<string, string> rendered cache keyed by "width\n<content>" */
    private array $previewCache = [];
    private ?Spinner $spinner = null;
    /** @var array{key: string, process: Process}|null */
    private ?array $fullPreviewJob = null;
    /** @var array{key: string, process: Process, fallbacks: array<string, string>}|null */
    private ?array $inlineBatchJob = null;

    public static function isCtrlS(string $seq): bool
    {
        return "\x13" === $seq || "\e[115;5u" === $seq || "\e[83;5u" === $seq;
    }

    /**
     * Enable Markdown live preview: inactive lines are rendered through the
     * given snippet renderer while the cursor line stays raw source. Preview is
     * turned on by default when the file is Markdown and a renderer is present.
     */
    public function enableMarkdownPreview(?MarkdownRenderer $renderer, string $filename, string $initialMode = 'inline'): void
    {
        $this->previewRenderer = $renderer;
        $this->previewFilename = $filename;
        $this->previewMode = self::isMarkdown($filename) && null !== $renderer ? $initialMode : 'off';
        $this->previewScroll = 0;
        $this->previewCache = [];
        $this->stopJobs();
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
        $this->lines = (false === $parts || [] === $parts || [''] === $parts) ? [''] : $parts;
        $this->line = 0;
        $this->col = 0;
        $this->dirty = true;
        $this->previewScroll = 0;
        $this->previewCache = [];
        $this->stopJobs();
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

    public function setSoftWrap(bool $enabled): void
    {
        $this->softWrap = $enabled;
    }

    public function isSoftWrap(): bool
    {
        return $this->softWrap;
    }

    public function setSpinner(?Spinner $spinner): void
    {
        $this->spinner = $spinner;
    }

    public function stopJobs(): void
    {
        if (null !== $this->fullPreviewJob && $this->fullPreviewJob['process']->isRunning()) {
            $this->fullPreviewJob['process']->stop(0);
        }
        $this->fullPreviewJob = null;

        if (null !== $this->inlineBatchJob && $this->inlineBatchJob['process']->isRunning()) {
            $this->inlineBatchJob['process']->stop(0);
        }
        $this->inlineBatchJob = null;
    }

    public function tick(?float $deltaTime = null): bool
    {
        $busy = false;

        if (null !== $this->fullPreviewJob) {
            $busy = true;
            if (!$this->fullPreviewJob['process']->isRunning()) {
                $job = $this->fullPreviewJob;
                $this->fullPreviewJob = null;
                $proc = $job['process'];
                $rendered = $proc->isSuccessful() ? $proc->getOutput() : '';
                if ('' === trim($rendered)) {
                    $rendered = implode("\n", $this->lines);
                }
                $this->previewCache[$job['key']] = $rendered;
            }
        }

        if (null !== $this->inlineBatchJob) {
            $busy = true;
            if (!$this->inlineBatchJob['process']->isRunning()) {
                $job = $this->inlineBatchJob;
                $this->inlineBatchJob = null;
                $proc = $job['process'];
                $decoded = $proc->isSuccessful() ? json_decode($proc->getOutput(), true) : null;
                foreach ($job['fallbacks'] as $k => $fallback) {
                    $val = \is_array($decoded) && isset($decoded[$k]) && \is_string($decoded[$k]) ? $decoded[$k] : null;
                    $this->previewCache[$k] = (null !== $val && '' !== trim($val)) ? $val : $fallback;
                }
            }
        }

        if ($busy && null !== $this->spinner) {
            $this->spinner->advance($deltaTime);
        }

        return $busy;
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
            array_splice($this->lines, $this->line, 1, [$before, $after]);
            $this->dirty = true;
            ++$this->line;
            $this->col = 0;

            return true;
        }

        if (Keys::BACKSPACE === $tok) {
            if ($this->col > 0) {
                $line = $this->lines[$this->line] ?? '';
                array_splice($this->lines, $this->line, 1, [mb_substr($line, 0, $this->col - 1).mb_substr($line, $this->col)]);
                $this->dirty = true;
                --$this->col;
            } elseif ($this->line > 0) {
                $prevLine = $this->lines[$this->line - 1];
                $curLine = $this->lines[$this->line] ?? '';
                $newCol = mb_strlen($prevLine);
                array_splice($this->lines, $this->line - 1, 2, [$prevLine.$curLine]);
                $this->dirty = true;
                --$this->line;
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
            array_splice($this->lines, $this->line, 1, [$before.$txt.$after]);
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

        $crumb = "\e[2m".Screen::fit('  '.$label, $cols)."\e[0m";
        $rows = max(1, $rows - 1);
        $this->lastRows = $rows;
        $count = \count($this->lines);
        $gutter = max(3, \strlen((string) $count));
        $codeWidth = max(1, $cols - $gutter - 2);

        if ($this->isInlinePreviewLoading($codeWidth, $highlighted)) {
            return array_merge([$crumb], Screen::spinnerBody($this->spinner ?? new Spinner(), 'Preparing editor...', $cols));
        }
        $preview = $this->isLivePreview();
        $styledMap = $preview ? $this->buildInlinePreviewMap($codeWidth, $highlighted) : [];

        if (!$this->softWrap) {
            $top = $count > $rows ? max(0, min($this->line - intdiv($rows, 2), $count - $rows)) : 0;
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
                    $g = "\e[7m".$num.'▶'."\e[0m".' ';
                    $styledLine = $this->renderCursor($highlightedLine, $line, $this->col);
                } else {
                    $g = "\e[2m".$num.'│'."\e[0m".' ';
                    $styledLine = $preview
                        ? ($styledMap[$idx] ?? $highlightedLine)
                        : $highlightedLine;
                }

                $rowsOut[] = Screen::fit($g.Screen::truncate($styledLine, $codeWidth), $cols);
            }

            return $rowsOut;
        }

        // Soft-wrap enabled: split lines into visual lines
        $visual = [];
        $activeVisIdx = 0;
        $activeColInVis = 0;

        foreach ($this->lines as $logIdx => $rawLine) {
            $isCursorLogical = ($logIdx === $this->line);
            $rawLineLen = mb_strlen($rawLine);

            if ($isCursorLogical) {
                $highlightedLine = $highlighted[$logIdx] ?? $rawLine;
                $chunks = self::wrapStringToVisualChunks($rawLine, $codeWidth);

                $chunkKeys = array_keys($chunks);
                $lastChunkKey = end($chunkKeys);
                $matched = false;

                foreach ($chunks as $cIdx => $c) {
                    $nextStart = isset($chunks[$cIdx + 1])
                        ? $chunks[$cIdx + 1]['start']
                        : ($c['start'] + $c['len'] + 1000);

                    $vIdx = \count($visual);
                    if (!$matched && ($this->col < $nextStart || $cIdx === $lastChunkKey)) {
                        $activeVisIdx = $vIdx;
                        $activeColInVis = min($c['len'], max(0, $this->col - $c['start']));
                        $matched = true;
                    }

                    $chunkStyled = self::sliceStyledAnsi($highlightedLine, $c['start'], $c['len']);
                    $visual[] = [
                        'logicalLine' => $logIdx,
                        'isFirst' => $c['isFirst'],
                        'isCursor' => true,
                        'rendered' => $chunkStyled,
                        'rawChunk' => $c['text'],
                        'chunkCol' => ($activeVisIdx === $vIdx) ? $activeColInVis : -1,
                    ];
                }
            } else {
                $styledFull = $preview
                    ? ($styledMap[$logIdx] ?? ($highlighted[$logIdx] ?? $rawLine))
                    : ($highlighted[$logIdx] ?? $rawLine);

                $chunks = self::wrapStringToVisualChunks($styledFull, $codeWidth);
                foreach ($chunks as $c) {
                    $visual[] = [
                        'logicalLine' => $logIdx,
                        'isFirst' => $c['isFirst'],
                        'isCursor' => false,
                        'rendered' => $c['text'],
                        'rawChunk' => $c['text'],
                        'chunkCol' => -1,
                    ];
                }
            }
        }

        $totalVisual = max(1, \count($visual));
        $top = $totalVisual > $rows ? max(0, min($activeVisIdx - intdiv($rows, 2), $totalVisual - $rows)) : 0;
        $rowsOut = [$crumb];

        for ($r = 0; $r < $rows; ++$r) {
            $vIdx = $top + $r;
            if ($vIdx >= $totalVisual) {
                $rowsOut[] = Screen::blank($cols);
                continue;
            }

            $v = $visual[$vIdx];
            $logIdx = $v['logicalLine'];
            $isFirst = $v['isFirst'];

            if ($isFirst) {
                $num = str_pad((string) ($logIdx + 1), $gutter, ' ', \STR_PAD_LEFT);
                $g = $logIdx === $this->line
                    ? "\e[7m".$num.'▶'."\e[0m".' '
                    : "\e[2m".$num.'│'."\e[0m".' ';
            } else {
                $num = str_repeat(' ', $gutter);
                $g = $logIdx === $this->line
                    ? "\e[7m".$num.' '."\e[0m".' '
                    : "\e[2m".$num.'│'."\e[0m".' ';
            }

            if ($v['isCursor'] && $vIdx === $activeVisIdx) {
                $styledLine = $this->renderCursor($v['rendered'], $v['rawChunk'], $activeColInVis);
            } else {
                $styledLine = $v['rendered'];
            }

            $rowsOut[] = Screen::fit($g.Screen::truncate($styledLine, $codeWidth), $cols);
        }

        return $rowsOut;
    }

    /**
     * Renders the editor lines without a breadcrumb header or outer box,
     * matching Go `pkg/ui/components/editor/editor.go` `View()`:
     * ` " " + digits + " │ " + content ` on every row up to `$rows` (even empty rows below EOF).
     *
     * @return list<string>
     */
    public function paintLines(int $cols, int $rows, bool $focused = true): array
    {
        $rows = max(1, $rows);
        $this->lastRows = $rows;
        if ($this->isFullPreview()) {
            $width = max(20, $cols);
            if ($this->isFullPreviewLoading($width)) {
                return Screen::spinnerBody($this->spinner ?? new Spinner(), 'Rendering preview...', $cols);
            }
            $lines = $this->fullPreviewLines($width);
            $total = \count($lines);
            $this->lastFullLineCount = $total;
            $maxScroll = max(0, $total - $rows);
            $this->previewScroll = max(0, min($this->previewScroll, $maxScroll));
            $out = [];
            for ($r = 0; $r < $rows; ++$r) {
                $idx = $this->previewScroll + $r;
                $out[] = $idx < $total ? Screen::fit($lines[$idx], $cols) : Screen::blank($cols);
            }

            return $out;
        }

        $count = max(1, \count($this->lines));
        $digits = max(1, \strlen((string) $count));
        $gutterWidth = $digits + 5; // "  " + digits + " │ "
        $codeWidth = max(1, $cols - $gutterWidth);
        $divider = Theme::style('│ ', Theme::BORDER2);
        $preview = $this->isLivePreview();
        if ($preview && $this->isInlinePreviewLoading($codeWidth, null)) {
            return Screen::spinnerBody($this->spinner ?? new Spinner(), 'Preparing editor...', $cols);
        }
        $styledMap = $preview ? $this->buildInlinePreviewMap($codeWidth, null) : [];

        if (!$this->softWrap) {
            $top = $count > $rows ? max(0, min($this->line - intdiv($rows, 2), $count - $rows)) : 0;
            $rowsOut = [];
            for ($r = 0; $r < $rows; ++$r) {
                $idx = $top + $r;
                $isCursorLine = $focused && $idx === $this->line;
                if ($idx < $count) {
                    $numStr = '  '.str_pad((string) ($idx + 1), $digits, ' ', \STR_PAD_LEFT).' ';
                    $gutter = $isCursorLine
                        ? Theme::style($numStr, Theme::BLUE, bold: true)
                        : Theme::style($numStr, Theme::MUTED2);
                } else {
                    $gutter = Theme::style('  '.str_repeat(' ', $digits).' ', Theme::MUTED2);
                }
                $gutter .= $divider;

                $renderedContent = '';
                if ($idx < \count($this->lines)) {
                    $line = $this->lines[$idx];
                    if ($isCursorLine) {
                        $renderedContent = $this->renderGoCursor($line, $this->col);
                    } elseif ($preview) {
                        $renderedContent = $styledMap[$idx] ?? $line;
                    } else {
                        $renderedContent = $line;
                    }
                }

                $rowsOut[] = Screen::fit($gutter.Screen::truncate($renderedContent, $codeWidth), $cols);
            }

            return $rowsOut;
        }

        // Soft-wrap enabled: split logical lines into visual lines
        $visual = $this->buildVisualLines($codeWidth);
        $totalVisual = max(1, \count($visual));

        // Find which visual line contains the active cursor
        $activeVisIdx = 0;
        $activeColInVis = 0;
        $lineChunks = [];
        foreach ($visual as $vIdx => $v) {
            if ($v['logicalLine'] === $this->line) {
                $lineChunks[$vIdx] = $v;
            }
        }
        if ([] !== $lineChunks) {
            $chunkKeys = array_keys($lineChunks);
            $lastChunkKey = end($chunkKeys);
            $matched = false;
            foreach ($lineChunks as $vIdx => $v) {
                $nextStart = isset($visual[$vIdx + 1]) && $visual[$vIdx + 1]['logicalLine'] === $this->line
                    ? $visual[$vIdx + 1]['start']
                    : ($v['start'] + $v['len'] + 1000);
                if ($this->col < $nextStart || $vIdx === $lastChunkKey) {
                    $activeVisIdx = $vIdx;
                    $activeColInVis = min($v['len'], max(0, $this->col - $v['start']));
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $activeVisIdx = $lastChunkKey;
                $activeColInVis = $visual[$lastChunkKey]['len'];
            }
        }

        $top = $totalVisual > $rows ? max(0, min($activeVisIdx - intdiv($rows, 2), $totalVisual - $rows)) : 0;
        $rowsOut = [];
        for ($r = 0; $r < $rows; ++$r) {
            $vIdx = $top + $r;
            if ($vIdx >= $totalVisual) {
                $gutter = Theme::style('  '.str_repeat(' ', $digits).' ', Theme::MUTED2).$divider;
                $rowsOut[] = Screen::fit($gutter, $cols);
                continue;
            }

            $v = $visual[$vIdx];
            $logIdx = $v['logicalLine'];
            $isFirst = $v['isFirst'];
            $isCursorLine = $focused && $vIdx === $activeVisIdx;

            if ($isFirst) {
                $numStr = '  '.str_pad((string) ($logIdx + 1), $digits, ' ', \STR_PAD_LEFT).' ';
                $gutter = $logIdx === $this->line && $focused
                    ? Theme::style($numStr, Theme::BLUE, bold: true)
                    : Theme::style($numStr, Theme::MUTED2);
            } else {
                $gutter = Theme::style('  '.str_repeat(' ', $digits).' ', Theme::MUTED2);
            }
            $gutter .= $divider;

            $chunkText = $v['text'];
            if ($isCursorLine) {
                $renderedContent = $this->renderGoCursor($chunkText, $activeColInVis);
            } else {
                $renderedContent = $chunkText;
            }

            $rowsOut[] = Screen::fit($gutter.Screen::truncate($renderedContent, $codeWidth), $cols);
        }

        return $rowsOut;
    }

    /**
     * @return list<array{logicalLine: int, isFirst: bool, text: string, start: int, len: int}>
     */
    private function buildVisualLines(int $width): array
    {
        $visual = [];
        foreach ($this->lines as $lineIdx => $lineText) {
            $totalLen = mb_strlen($lineText);
            if ($width <= 0 || $totalLen <= $width) {
                $visual[] = [
                    'logicalLine' => $lineIdx,
                    'isFirst' => true,
                    'text' => $lineText,
                    'start' => 0,
                    'len' => $totalLen,
                ];
                continue;
            }

            $offset = 0;
            $isFirst = true;
            while ($offset < $totalLen) {
                $remaining = mb_substr($lineText, $offset);
                $remLen = mb_strlen($remaining);
                if ($remLen <= $width) {
                    $visual[] = [
                        'logicalLine' => $lineIdx,
                        'isFirst' => $isFirst,
                        'text' => $remaining,
                        'start' => $offset,
                        'len' => $remLen,
                    ];
                    break;
                }

                $slice = mb_substr($remaining, 0, $width + 1);
                $lastSpace = mb_strrpos($slice, ' ');

                if (false !== $lastSpace && $lastSpace > 0) {
                    $chunkText = mb_substr($remaining, 0, $lastSpace);
                    $visual[] = [
                        'logicalLine' => $lineIdx,
                        'isFirst' => $isFirst,
                        'text' => $chunkText,
                        'start' => $offset,
                        'len' => $lastSpace,
                    ];
                    $offset += $lastSpace + 1;
                } else {
                    $chunkText = mb_substr($remaining, 0, $width);
                    $visual[] = [
                        'logicalLine' => $lineIdx,
                        'isFirst' => $isFirst,
                        'text' => $chunkText,
                        'start' => $offset,
                        'len' => $width,
                    ];
                    $offset += $width;
                }
                $isFirst = false;
            }
        }

        return [] === $visual ? [['logicalLine' => 0, 'isFirst' => true, 'text' => '', 'start' => 0, 'len' => 0]] : $visual;
    }

    private function renderGoCursor(string $rawLine, int $col): string
    {
        $rawLen = mb_strlen($rawLine);
        $col = max(0, min($col, $rawLen));
        $before = mb_substr($rawLine, 0, $col);
        $char = $col < $rawLen ? mb_substr($rawLine, $col, 1) : ' ';
        $after = $col < $rawLen ? mb_substr($rawLine, $col + 1) : '';
        $cursorRendered = Theme::style($char, Theme::BG, Theme::BLUE, bold: true);

        return $this->styleMarkdownLineGhost($before).$cursorRendered.$this->styleMarkdownLineGhost($after);
    }

    private function styleMarkdownLineGhost(string $segment): string
    {
        if ('' === $segment) {
            return '';
        }

        return preg_replace_callback('/(\*\*|__|~~|`)/', static fn (array $m): string => Theme::style($m[1], Theme::MUTED, faint: true), $segment) ?? $segment;
    }

    /**
     * @return list<string>
     */
    private function paintFullPreview(string $label, int $cols, int $rows): array
    {
        $crumb = "\e[2m".Screen::fit('  '.$label, $cols)."\e[0m";
        $bodyRows = max(1, $rows - 1);
        $this->lastRows = $bodyRows;
        $width = max(20, $cols - 2);
        if ($this->isFullPreviewLoading($width)) {
            return array_merge([$crumb], Screen::spinnerBody($this->spinner ?? new Spinner(), 'Rendering preview...', $cols));
        }

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
        $key = $width."\n".$content;
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

        return false === $lines || [] === $lines ? [''] : $lines;
    }

    private function isFullPreviewLoading(int $width): bool
    {
        if (!$this->previewRenderer instanceof TermstrapRenderer || !$this->previewRenderer->isAvailable()) {
            return false;
        }
        $content = $this->value();
        $key = $width."\n".$content;
        if (isset($this->previewCache[$key])) {
            return false;
        }
        if (null === $this->fullPreviewJob || $this->fullPreviewJob['key'] !== $key) {
            if (null !== $this->fullPreviewJob && $this->fullPreviewJob['process']->isRunning()) {
                $this->fullPreviewJob['process']->stop(0);
            }
            try {
                $this->fullPreviewJob = [
                    'key' => $key,
                    'process' => $this->previewRenderer->startRenderDocumentProcess($this->previewFilename, $content, $width),
                ];
            } catch (\Throwable) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string>|null $highlighted
     */
    private function isInlinePreviewLoading(int $codeWidth, ?array $highlighted): bool
    {
        if (!$this->isLivePreview() || !$this->previewRenderer instanceof TermstrapRenderer || !$this->previewRenderer->isAvailable()) {
            return false;
        }

        $docDefs = '';
        foreach ($this->lines as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '[') && str_contains($trimmed, ']:')) {
                $docDefs .= $trimmed."\n";
            }
        }

        $inFence = false;
        $missing = [];
        $fallbacks = [];
        $windowStart = max(0, $this->line - intdiv($this->lastRows, 2) - 5);
        $windowEnd = min(\count($this->lines), $windowStart + $this->lastRows + 10);

        for ($idx = $windowStart; $idx < $windowEnd; ++$idx) {
            $line = $this->lines[$idx];
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '```') || str_starts_with($trimmed, '~~~')) {
                continue;
            }
            if ('' === trim($line) || $idx === $this->line) {
                continue;
            }
            $key = $codeWidth."\n".$line;
            if (!isset($this->previewCache[$key])) {
                $missing[$key] = $line;
                $fallbacks[$key] = $highlighted[$idx] ?? $line;
            }
        }
        if ([] === $missing) {
            return false;
        }
        $batchKey = $codeWidth.':'.md5(implode("\n", array_keys($missing)));
        if (null === $this->inlineBatchJob || $this->inlineBatchJob['key'] !== $batchKey) {
            if (null !== $this->inlineBatchJob && $this->inlineBatchJob['process']->isRunning()) {
                $this->inlineBatchJob['process']->stop(0);
            }
            try {
                $this->inlineBatchJob = [
                    'key' => $batchKey,
                    'process' => $this->previewRenderer->startRenderSnippetsBatchProcess($this->previewFilename, $missing, $codeWidth, $docDefs),
                    'fallbacks' => $fallbacks,
                ];
            } catch (\Throwable) {
                return false;
            }
        }

        return true;
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
                $docDefs .= $trimmed."\n";
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
                $styledMap[$idx] = "\e[2m".$line."\e[0m";
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
        $key = $width."\n".$rawLine;
        if (isset($this->previewCache[$key])) {
            return $this->previewCache[$key];
        }

        // Non-Termstrap fallback or immediate return of fallback so we never block in the paint cycle
        if (!$this->previewRenderer instanceof TermstrapRenderer && null !== $this->previewRenderer && $this->previewRenderer->isAvailable()) {
            try {
                $rendered = $this->previewRenderer->renderSnippet($this->previewFilename, $rawLine, $width, $docDefs);
            } catch (\Throwable) {
                $rendered = null;
            }

            return $this->previewCache[$key] = (null !== $rendered && '' !== trim($rendered)) ? $rendered : ($fallback ?? $rawLine);
        }

        return $fallback ?? $rawLine;
    }

    private function renderCursor(string $highlightedLine, string $rawLine, int $col): string
    {
        $rawLen = mb_strlen($rawLine);
        $col = max(0, min($col, $rawLen));

        if (!str_contains($highlightedLine, "\e")) {
            $before = mb_substr($rawLine, 0, $col);
            $char = $col < $rawLen ? mb_substr($rawLine, $col, 1) : ' ';
            $after = $col < $rawLen ? mb_substr($rawLine, $col + 1) : '';

            return $before."\e[7m".$char."\e[0m".$after;
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

                $result .= $before."\e[7m".$char."\e[27m".$after;
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

    /**
     * Slices an ANSI-formatted string by visible character offsets without breaking escape codes.
     */
    public static function sliceStyledAnsi(string $styled, int $startCol, int $len): string
    {
        if ($len <= 0) {
            return '';
        }
        if (!str_contains($styled, "\e")) {
            return mb_substr($styled, $startCol, $len);
        }

        $ansiRe = '/\e\[[0-9;?]*[ -\/]*[@-~]|\e\][^\a\e]*(?:\a|\e\\\\)/';
        $parts = preg_split('/('.substr($ansiRe, 1, -1).')/', $styled, -1, \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY);
        if (false === $parts) {
            return mb_substr($styled, $startCol, $len);
        }

        $out = '';
        $activeStyles = '';
        $currCol = 0;
        $targetEnd = $startCol + $len;

        foreach ($parts as $part) {
            if (str_starts_with($part, "\e")) {
                if ("\e[0m" === $part) {
                    $activeStyles = '';
                } else {
                    $activeStyles .= $part;
                }
                if ($currCol > $startCol && $currCol < $targetEnd) {
                    $out .= $part;
                }
                continue;
            }

            $partLen = mb_strlen($part);
            $partStart = $currCol;
            $partEnd = $currCol + $partLen;
            $currCol = $partEnd;

            $overlapStart = max($partStart, $startCol);
            $overlapEnd = min($partEnd, $targetEnd);

            if ($overlapStart < $overlapEnd) {
                if ('' === $out && '' !== $activeStyles) {
                    $out .= $activeStyles;
                }
                $subOffset = $overlapStart - $partStart;
                $subLen = $overlapEnd - $overlapStart;
                $out .= mb_substr($part, $subOffset, $subLen);
            }
        }

        if ('' !== $out && str_contains($out, "\e") && !str_ends_with($out, "\e[0m")) {
            $out .= "\e[0m";
        }

        return $out;
    }

    /**
     * Splits plain or ANSI text into visual line chunks respecting word boundaries and maximum visible width.
     *
     * @return list<array{isFirst: bool, text: string, start: int, len: int}>
     */
    public static function wrapStringToVisualChunks(string $lineText, int $width): array
    {
        if ('' === $lineText) {
            return [['isFirst' => true, 'text' => '', 'start' => 0, 'len' => 0]];
        }

        $hasAnsi = str_contains($lineText, "\e");
        $totalLen = $hasAnsi ? Screen::width($lineText) : mb_strlen($lineText);

        if ($width <= 0 || $totalLen <= $width) {
            return [['isFirst' => true, 'text' => $lineText, 'start' => 0, 'len' => mb_strlen($lineText)]];
        }

        if ($hasAnsi) {
            return self::wrapAnsiStringToVisualChunks($lineText, $width);
        }

        $visual = [];
        $offset = 0;
        $isFirst = true;

        while ($offset < $totalLen) {
            $remaining = mb_substr($lineText, $offset);
            $remLen = mb_strlen($remaining);

            if ($remLen <= $width) {
                $visual[] = [
                    'isFirst' => $isFirst,
                    'text' => $remaining,
                    'start' => $offset,
                    'len' => $remLen,
                ];
                break;
            }

            $slice = mb_substr($remaining, 0, $width + 1);
            $lastSpace = mb_strrpos($slice, ' ');

            if (false !== $lastSpace && $lastSpace > 0) {
                $chunkText = mb_substr($remaining, 0, $lastSpace);
                $visual[] = [
                    'isFirst' => $isFirst,
                    'text' => $chunkText,
                    'start' => $offset,
                    'len' => $lastSpace,
                ];
                $offset += $lastSpace + 1;
            } else {
                $chunkText = mb_substr($remaining, 0, $width);
                $visual[] = [
                    'isFirst' => $isFirst,
                    'text' => $chunkText,
                    'start' => $offset,
                    'len' => $width,
                ];
                $offset += $width;
            }
            $isFirst = false;
        }

        return $visual;
    }

    /**
     * @return list<array{isFirst: bool, text: string, start: int, len: int}>
     */
    private static function wrapAnsiStringToVisualChunks(string $line, int $width): array
    {
        if ($width <= 0) {
            return [['isFirst' => true, 'text' => '', 'start' => 0, 'len' => 0]];
        }

        $ansiRe = '/\e\[[0-9;?]*[ -\/]*[@-~]|\e\][^\a\e]*(?:\a|\e\\\\)/';
        $tokens = [];
        $len = \strlen($line);
        $i = 0;
        $plainOffset = 0;

        while ($i < $len) {
            if ("\e" === $line[$i] && preg_match($ansiRe, $line, $m, 0, $i) && 0 === strpos($line, $m[0], $i)) {
                $tokens[] = ['isAnsi' => true, 'raw' => $m[0], 'char' => '', 'charWidth' => 0, 'plainOffset' => $plainOffset];
                $i += \strlen($m[0]);
                continue;
            }

            $byte = \ord($line[$i]);
            if ($byte < 0x80) {
                $glen = 1;
            } elseif ($byte >= 0xF0) {
                $glen = 4;
            } elseif ($byte >= 0xE0) {
                $glen = 3;
            } elseif ($byte >= 0xC0) {
                $glen = 2;
            } else {
                $glen = 1;
            }

            $char = substr($line, $i, $glen);
            $cw = mb_strwidth($char, 'UTF-8');
            $tokens[] = ['isAnsi' => false, 'raw' => $char, 'char' => $char, 'charWidth' => $cw, 'plainOffset' => $plainOffset];
            $i += $glen;
            $plainOffset += mb_strlen($char);
        }

        $totalPlainLen = $plainOffset;
        if (Screen::width($line) <= $width) {
            return [['isFirst' => true, 'text' => $line, 'start' => 0, 'len' => $totalPlainLen]];
        }

        $chunks = [];
        $tokenCount = \count($tokens);
        $currToken = 0;
        $activeAnsi = '';
        $isFirst = true;

        while ($currToken < $tokenCount) {
            $accumWidth = 0;
            $chunkTokens = [];
            $lastSpaceTokenIdx = -1;
            $hasChars = false;

            $t = $currToken;
            while ($t < $tokenCount) {
                $tok = $tokens[$t];
                if ($tok['isAnsi']) {
                    $chunkTokens[] = $tok;
                    ++$t;
                    continue;
                }
                if ($accumWidth + $tok['charWidth'] > $width && $hasChars) {
                    break;
                }
                $chunkTokens[] = $tok;
                $accumWidth += $tok['charWidth'];
                $hasChars = true;
                if (' ' === $tok['char']) {
                    $lastSpaceTokenIdx = \count($chunkTokens) - 1;
                }
                ++$t;
            }

            if ($t >= $tokenCount) {
                $tokensToKeep = $chunkTokens;
                $currToken = $t;
            } elseif ($lastSpaceTokenIdx > 0 && $lastSpaceTokenIdx < \count($chunkTokens)) {
                $tokensToKeep = \array_slice($chunkTokens, 0, $lastSpaceTokenIdx);
                $currToken += $lastSpaceTokenIdx + 1;
            } else {
                $tokensToKeep = $chunkTokens;
                $currToken = $t;
            }

            $chunkRaw = $activeAnsi;
            $startOffset = -1;
            $endOffset = -1;
            foreach ($tokensToKeep as $ct) {
                $chunkRaw .= $ct['raw'];
                if ($ct['isAnsi']) {
                    if ("\e[0m" === $ct['raw']) {
                        $activeAnsi = '';
                    } else {
                        $activeAnsi .= $ct['raw'];
                    }
                } else {
                    if (-1 === $startOffset) {
                        $startOffset = $ct['plainOffset'];
                    }
                    $endOffset = $ct['plainOffset'] + mb_strlen($ct['char']);
                }
            }
            if (-1 === $startOffset) {
                $startOffset = 0;
                $endOffset = 0;
            }
            if (str_contains($chunkRaw, "\e") && !str_ends_with($chunkRaw, "\e[0m")) {
                $chunkRaw .= "\e[0m";
            }

            $chunks[] = [
                'isFirst' => $isFirst,
                'text' => $chunkRaw,
                'start' => $startOffset,
                'len' => $endOffset - $startOffset,
            ];
            $isFirst = false;
        }

        return $chunks;
    }
}

<?php

declare(strict_types=1);

namespace CodesSh\Tui\Component;

use CodesSh\Tui\Event\InputEvent;
use CodesSh\Tui\Event\KeyMsg;
use CodesSh\Tui\Event\MouseMsg;
use CodesSh\Tui\Style\Ansi;
use CodesSh\Tui\Style\TokyoNight;
use CodesSh\Tui\Style\ZoneManager;

final class CodeViewerComponent implements ComponentInterface
{
    private string $filename;
    /** @var string[] */
    private array $lines = [];
    private int $cursorLine = 0;
    private int $yOffset = 0;
    private int $width = 80;
    private int $height = 24;
    private bool $showLineNumbers = true;

    public function __construct(string $filename = '', string $code = '')
    {
        $this->filename = $filename;
        $this->setCode($code);
    }

    public function init(): void
    {
        $this->cursorLine = 0;
        $this->yOffset = 0;
    }

    public function setCode(string $code): void
    {
        $normalized = str_replace("\r\n", "\n", $code);
        $this->lines = explode("\n", $normalized);
        if (empty($this->lines)) {
            $this->lines = [''];
        }
        $this->cursorLine = min($this->cursorLine, count($this->lines) - 1);
    }

    public function setFilename(string $filename): void
    {
        $this->filename = $filename;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getCursorLine(): int
    {
        return $this->cursorLine;
    }

    public function setCursorLine(int $line): void
    {
        if ($line >= 0 && $line < count($this->lines)) {
            $this->cursorLine = $line;
            $this->clampViewport();
        }
    }

    public function toggleLineNumbers(): void
    {
        $this->showLineNumbers = !$this->showLineNumbers;
    }

    public function isShowLineNumbers(): bool
    {
        return $this->showLineNumbers;
    }

    public function getCurrentLineContent(): string
    {
        return $this->lines[$this->cursorLine] ?? '';
    }

    public function getAllContent(): string
    {
        return implode("\n", $this->lines);
    }

    public function setSize(int $width, int $height): void
    {
        $this->width = max(1, $width);
        $this->height = max(1, $height);
        $this->clampViewport();
    }

    private function clampViewport(): void
    {
        if ($this->cursorLine < $this->yOffset) {
            $this->yOffset = $this->cursorLine;
        } elseif ($this->cursorLine >= $this->yOffset + $this->height) {
            $this->yOffset = $this->cursorLine - $this->height + 1;
        }
    }

    public function update(InputEvent $event): ?string
    {
        if ($event instanceof KeyMsg) {
            if ($event->is(KeyMsg::UP) || $event->is('k')) {
                if ($this->cursorLine > 0) {
                    --$this->cursorLine;
                    $this->clampViewport();
                }

                return null;
            }

            if ($event->is(KeyMsg::DOWN) || $event->is('j')) {
                if ($this->cursorLine < count($this->lines) - 1) {
                    ++$this->cursorLine;
                    $this->clampViewport();
                }

                return null;
            }

            if ($event->is(KeyMsg::PAGE_UP) || $event->is('u')) {
                $this->cursorLine = max(0, $this->cursorLine - ($this->height - 2));
                $this->clampViewport();

                return null;
            }

            if ($event->is(KeyMsg::PAGE_DOWN) || $event->is('d')) {
                $this->cursorLine = min(count($this->lines) - 1, $this->cursorLine + ($this->height - 2));
                $this->clampViewport();

                return null;
            }

            if ($event->is(KeyMsg::HOME) || $event->is('g')) {
                $this->cursorLine = 0;
                $this->clampViewport();

                return null;
            }

            if ($event->is(KeyMsg::END) || $event->is('G')) {
                $this->cursorLine = count($this->lines) - 1;
                $this->clampViewport();

                return null;
            }

            if ($event->is('l')) {
                $this->toggleLineNumbers();

                return null;
            }

            if ($event->is('c')) {
                $content = $this->getAllContent();
                echo "\x1b]52;c;".base64_encode($content)."\x07";

                return 'copy';
            }

            if ($event->is('gd')) {
                return 'goto_def';
            }

            if ($event->is('gr')) {
                return 'find_refs';
            }
        }

        if ($event instanceof MouseMsg) {
            if ($event->isWheelUp()) {
                if ($this->cursorLine > 0) {
                    --$this->cursorLine;
                    $this->clampViewport();
                }

                return null;
            }

            if ($event->isWheelDown()) {
                if ($this->cursorLine < count($this->lines) - 1) {
                    ++$this->cursorLine;
                    $this->clampViewport();
                }

                return null;
            }
        }

        return null;
    }

    public function view(int $width, int $height): string
    {
        $this->width = $width;
        $this->height = $height;
        $this->clampViewport();

        $totalLines = count($this->lines);
        $numDigits = max(3, strlen((string) $totalLines));
        $reset = TokyoNight::reset();

        $mutedColor = TokyoNight::hexToAnsi(TokyoNight::MUTED);
        $activeBorderColor = TokyoNight::hexToAnsi(TokyoNight::ACTIVE_BORDER);
        $selectionBg = TokyoNight::hexToAnsi(TokyoNight::SELECTION, true);
        $cyanColor = TokyoNight::hexToAnsi(TokyoNight::CYAN);

        $visibleLines = [];
        $linkCounter = 0;

        for ($i = 0; $i < $height; ++$i) {
            $lineIdx = $this->yOffset + $i;
            if ($lineIdx >= $totalLines) {
                $visibleLines[] = '';
                continue;
            }

            $rawContent = $this->lines[$lineIdx];
            $isCurrent = ($lineIdx === $this->cursorLine);

            // Detect and mark Markdown links [text](url) and OSC 8 links
            $parsedContent = $this->detectAndMarkLinks($rawContent, $linkCounter);

            // Gutter formatting: cursor indicator "▌", line number, separator "│"
            $lineNum = sprintf('%'.$numDigits.'d', $lineIdx + 1);

            if ($this->showLineNumbers) {
                if ($isCurrent) {
                    $cursorIndicator = $activeBorderColor.'▌'.$reset;
                    $gutterNum = $activeBorderColor.$lineNum.$reset;
                    $sep = $activeBorderColor.'│'.$reset;
                    $lineBg = $selectionBg;
                } else {
                    $cursorIndicator = ' ';
                    $gutterNum = $mutedColor.$lineNum.$reset;
                    $sep = $mutedColor.'│'.$reset;
                    $lineBg = '';
                }
                $prefix = $cursorIndicator.' '.$gutterNum.' '.$sep.' ';
                $prefixWidth = 1 + 1 + $numDigits + 1 + 1 + 1; // "▌ 123 │ "
            } else {
                if ($isCurrent) {
                    $cursorIndicator = $activeBorderColor.'▌'.$reset;
                    $lineBg = $selectionBg;
                } else {
                    $cursorIndicator = ' ';
                    $lineBg = '';
                }
                $prefix = $cursorIndicator.' ';
                $prefixWidth = 2;
            }
            // Available width for code
            $codeWidth = max(10, $width - $prefixWidth);

            // Soft wrap handling if content exceeds codeWidth
            $contentVisLen = Ansi::width($parsedContent);
            if ($contentVisLen > $codeWidth) {
                // Truncate or soft-wrap (first chunk for single-line display)
                $parsedContent = Ansi::truncate($parsedContent, $codeWidth, '…');
            }

            if ('' !== $lineBg) {
                $contentVis = Ansi::width($parsedContent);
                $pad = max(0, $codeWidth - $contentVis);
                $renderedLine = $prefix.$lineBg.$parsedContent.str_repeat(' ', $pad).$reset;
            } else {
                $renderedLine = $prefix.$parsedContent;
            }

            $visibleLines[] = $renderedLine;
        }

        return implode("\n", $visibleLines);
    }

    private function detectAndMarkLinks(string $content, int &$linkCounter): string
    {
        // 1. Detect Markdown links: [text](url)
        $pattern = '/\[([^\]]+)\]\(([^)]+)\)/';
        $res = preg_replace_callback($pattern, static function ($matches) use (&$linkCounter) {
            ++$linkCounter;
            $text = $matches[1];
            $url = $matches[2];
            $cyan = TokyoNight::hexToAnsi(TokyoNight::CYAN);
            $reset = TokyoNight::reset();
            $linkContent = "\x1b[4m".$cyan.$text.$reset;

            return ZoneManager::mark('link_'.$linkCounter, $linkContent);
        }, $content);

        // 2. Detect OSC 8 links: \x1b]8;;url\x1b\text\x1b]8;;\x1b\
        $oscPattern = '/\x1b\]8;;(.*?)(?:\x1b\\\\|\x07)(.*?)\x1b\]8;;(?:\x1b\\\\|\x07)/';
        $res = preg_replace_callback($oscPattern, static function ($matches) use (&$linkCounter) {
            ++$linkCounter;
            $text = $matches[2];
            $cyan = TokyoNight::hexToAnsi(TokyoNight::CYAN);
            $reset = TokyoNight::reset();
            $linkContent = "\x1b[4m".$cyan.$text.$reset;

            return ZoneManager::mark('link_'.$linkCounter, $linkContent);
        }, (string) $res);

        return (string) $res;
    }
}

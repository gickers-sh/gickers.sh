<?php

declare(strict_types=1);

namespace App\Tui\Component;

use App\Service\Render\TermstrapRenderer;
use App\Tui\Event\InputEvent;
use App\Tui\Event\KeyMsg;
use App\Tui\Event\MouseMsg;
use App\Tui\Style\Ansi;
use App\Tui\Style\TokyoNight;

final class EditorComponent implements ComponentInterface
{
    private string $filename;
    /** @var string[] */
    private array $lines = [];
    private int $cursorRow = 0;
    private int $cursorCol = 0;
    private int $yOffset = 0;
    private bool $livePreview = false;
    private ?TermstrapRenderer $renderer = null;

    public function __construct(string $filename = 'untitled.md', string $initialContent = '', ?TermstrapRenderer $renderer = null)
    {
        $this->filename = $filename;
        $this->setContent($initialContent);
        $this->renderer = $renderer;
    }

    public function init(): void
    {
        $this->cursorRow = 0;
        $this->cursorCol = 0;
        $this->yOffset = 0;
    }

    public function setContent(string $content): void
    {
        $normalized = str_replace("\r\n", "\n", $content);
        $this->lines = explode("\n", $normalized);
        if (empty($this->lines)) {
            $this->lines = [''];
        }
        $this->cursorRow = min($this->cursorRow, count($this->lines) - 1);
        $this->cursorCol = min($this->cursorCol, mb_strlen($this->lines[$this->cursorRow], 'UTF-8'));
    }

    public function getContent(): string
    {
        return implode("\n", $this->lines);
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): void
    {
        $this->filename = $filename;
    }

    public function isLivePreview(): bool
    {
        return $this->livePreview;
    }

    public function toggleLivePreview(): void
    {
        $this->livePreview = !$this->livePreview;
    }

    public function getCursorPosition(): array
    {
        return ['row' => $this->cursorRow, 'col' => $this->cursorCol];
    }

    public function setCursorPosition(int $row, int $col): void
    {
        if ($row >= 0 && $row < count($this->lines)) {
            $this->cursorRow = $row;
            $len = mb_strlen($this->lines[$row], 'UTF-8');
            $this->cursorCol = max(0, min($col, $len));
        }
    }

    public function update(InputEvent $event): ?string
    {
        if (!$event instanceof KeyMsg) {
            return null;
        }

        // Toggle live preview with ctrl+p
        if ($event->is('ctrl+p')) {
            $this->toggleLivePreview();
            return null;
        }

        if ($event->is(KeyMsg::UP)) {
            if ($this->cursorRow > 0) {
                $this->cursorRow--;
                $len = mb_strlen($this->lines[$this->cursorRow], 'UTF-8');
                $this->cursorCol = min($this->cursorCol, $len);
            }
            return null;
        }

        if ($event->is(KeyMsg::DOWN)) {
            if ($this->cursorRow < count($this->lines) - 1) {
                $this->cursorRow++;
                $len = mb_strlen($this->lines[$this->cursorRow], 'UTF-8');
                $this->cursorCol = min($this->cursorCol, $len);
            }
            return null;
        }

        if ($event->is(KeyMsg::LEFT)) {
            if ($this->cursorCol > 0) {
                $this->cursorCol--;
            } elseif ($this->cursorRow > 0) {
                $this->cursorRow--;
                $this->cursorCol = mb_strlen($this->lines[$this->cursorRow], 'UTF-8');
            }
            return null;
        }

        if ($event->is(KeyMsg::RIGHT)) {
            $len = mb_strlen($this->lines[$this->cursorRow], 'UTF-8');
            if ($this->cursorCol < $len) {
                $this->cursorCol++;
            } elseif ($this->cursorRow < count($this->lines) - 1) {
                $this->cursorRow++;
                $this->cursorCol = 0;
            }
            return null;
        }

        if ($event->is(KeyMsg::HOME)) {
            $this->cursorCol = 0;
            return null;
        }

        if ($event->is(KeyMsg::END)) {
            $this->cursorCol = mb_strlen($this->lines[$this->cursorRow], 'UTF-8');
            return null;
        }

        if ($event->is(KeyMsg::ENTER)) {
            $curLine = $this->lines[$this->cursorRow];
            $left = mb_substr($curLine, 0, $this->cursorCol, 'UTF-8');
            $right = mb_substr($curLine, $this->cursorCol, null, 'UTF-8');

            $this->lines[$this->cursorRow] = $left;
            array_splice($this->lines, $this->cursorRow + 1, 0, [$right]);
            $this->cursorRow++;
            $this->cursorCol = 0;
            return null;
        }

        if ($event->is(KeyMsg::BACKSPACE)) {
            $curLine = $this->lines[$this->cursorRow];
            if ($this->cursorCol > 0) {
                $left = mb_substr($curLine, 0, $this->cursorCol - 1, 'UTF-8');
                $right = mb_substr($curLine, $this->cursorCol, null, 'UTF-8');
                $this->lines[$this->cursorRow] = $left . $right;
                $this->cursorCol--;
            } elseif ($this->cursorRow > 0) {
                $prevLine = $this->lines[$this->cursorRow - 1];
                $newCol = mb_strlen($prevLine, 'UTF-8');
                $this->lines[$this->cursorRow - 1] = $prevLine . $curLine;
                array_splice($this->lines, $this->cursorRow, 1);
                $this->cursorRow--;
                $this->cursorCol = $newCol;
            }
            return null;
        }

        if ($event->is(KeyMsg::DELETE)) {
            $curLine = $this->lines[$this->cursorRow];
            $len = mb_strlen($curLine, 'UTF-8');
            if ($this->cursorCol < $len) {
                $left = mb_substr($curLine, 0, $this->cursorCol, 'UTF-8');
                $right = mb_substr($curLine, $this->cursorCol + 1, null, 'UTF-8');
                $this->lines[$this->cursorRow] = $left . $right;
            } elseif ($this->cursorRow < count($this->lines) - 1) {
                $nextLine = $this->lines[$this->cursorRow + 1];
                $this->lines[$this->cursorRow] = $curLine . $nextLine;
                array_splice($this->lines, $this->cursorRow + 1, 1);
            }
            return null;
        }

        // Typing runes
        if ($event->runes !== '' && !$event->ctrl && !$event->alt) {
            $curLine = $this->lines[$this->cursorRow];
            $left = mb_substr($curLine, 0, $this->cursorCol, 'UTF-8');
            $right = mb_substr($curLine, $this->cursorCol, null, 'UTF-8');
            $this->lines[$this->cursorRow] = $left . $event->runes . $right;
            $this->cursorCol += mb_strlen($event->runes, 'UTF-8');
            return null;
        }

        return null;
    }

    public function view(int $width, int $height): string
    {
        $totalLines = count($this->lines);
        $numDigits = max(3, strlen((string) $totalLines));
        $reset = TokyoNight::reset();

        $activeBorderColor = TokyoNight::hexToAnsi(TokyoNight::ACTIVE_BORDER);
        $mutedColor = TokyoNight::hexToAnsi(TokyoNight::MUTED);

        // Clamp yOffset
        if ($this->cursorRow < $this->yOffset) {
            $this->yOffset = $this->cursorRow;
        } elseif ($this->cursorRow >= $this->yOffset + $height) {
            $this->yOffset = $this->cursorRow - $height + 1;
        }

        $renderedLines = [];

        for ($i = 0; $i < $height; $i++) {
            $lineIdx = $this->yOffset + $i;
            if ($lineIdx >= $totalLines) {
                $renderedLines[] = '';
                continue;
            }

            $rawText = $this->lines[$lineIdx];
            $isCurrent = ($lineIdx === $this->cursorRow);

            $lineNum = sprintf('%' . $numDigits . 'd', $lineIdx + 1);
            if ($isCurrent) {
                $cursorIndicator = $activeBorderColor . '▌' . $reset;
                $gutterNum = $activeBorderColor . $lineNum . $reset;
                $sep = $activeBorderColor . '│' . $reset;
            } else {
                $cursorIndicator = ' ';
                $gutterNum = $mutedColor . $lineNum . $reset;
                $sep = $mutedColor . '│' . $reset;
            }

            $prefix = $cursorIndicator . ' ' . $gutterNum . ' ' . $sep . ' ';
            $prefixWidth = 1 + 1 + $numDigits + 1 + 1 + 1;
            $codeWidth = max(10, $width - $prefixWidth);

            // Line content rendering
            if ($isCurrent) {
                // Render with inverted cursor
                $chars = mb_str_split($rawText, 1, 'UTF-8');
                $len = count($chars);
                $content = '';
                for ($c = 0; $c <= $len; $c++) {
                    $ch = $chars[$c] ?? ' ';
                    if ($c === $this->cursorCol) {
                        $content .= "\x1b[7m" . $ch . "\x1b[27m";
                    } elseif ($c < $len) {
                        $content .= TokyoNight::hexToAnsi(TokyoNight::FG) . $ch . $reset;
                    }
                }
            } else {
                // If live preview is active and renderer is available, render snippet preview for inactive lines
                if ($this->livePreview && $this->renderer !== null) {
                    $snippet = $this->renderer->renderSnippet($this->filename, $rawText, $codeWidth);
                    $content = $snippet !== '' ? $snippet : TokyoNight::hexToAnsi(TokyoNight::FG) . $rawText . $reset;
                } else {
                    $content = TokyoNight::hexToAnsi(TokyoNight::FG) . $rawText . $reset;
                }
            }

            $renderedLines[] = $prefix . $content;
        }

        return implode("\n", $renderedLines);
    }
}

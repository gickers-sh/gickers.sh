<?php

declare(strict_types=1);

namespace CodesSh\Tui\Component\Field;

use CodesSh\Tui\Event\KeyMsg;
use CodesSh\Tui\Style\Ansi;
use CodesSh\Tui\Style\TokyoNight;

final class TextAreaField implements FormFieldInterface
{
    private string $id;
    private string $label;
    /** @var string[] */
    private array $lines;
    private int $cursorRow = 0;
    private int $cursorCol = 0;
    private int $height;

    public function __construct(string $id, string $label, string $initialValue = '', int $height = 5)
    {
        $this->id = $id;
        $this->label = $label;
        $this->height = max(2, $height);
        $this->setValue($initialValue);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function getValue(): string
    {
        return implode("\n", $this->lines);
    }

    public function setValue(mixed $val): void
    {
        $str = (string) $val;
        $str = str_replace("\r\n", "\n", $str);
        $this->lines = explode("\n", $str);
        if (empty($this->lines)) {
            $this->lines = [''];
        }
        $this->cursorRow = count($this->lines) - 1;
        $this->cursorCol = mb_strlen($this->lines[$this->cursorRow], 'UTF-8');
    }

    public function handleKey(KeyMsg $key): bool
    {
        if ($key->is(KeyMsg::UP)) {
            if ($this->cursorRow > 0) {
                --$this->cursorRow;
                $lineLen = mb_strlen($this->lines[$this->cursorRow], 'UTF-8');
                $this->cursorCol = min($this->cursorCol, $lineLen);

                return true;
            }

            return false;
        }

        if ($key->is(KeyMsg::DOWN)) {
            if ($this->cursorRow < count($this->lines) - 1) {
                ++$this->cursorRow;
                $lineLen = mb_strlen($this->lines[$this->cursorRow], 'UTF-8');
                $this->cursorCol = min($this->cursorCol, $lineLen);

                return true;
            }

            return false;
        }

        if ($key->is(KeyMsg::LEFT)) {
            if ($this->cursorCol > 0) {
                --$this->cursorCol;
            } elseif ($this->cursorRow > 0) {
                --$this->cursorRow;
                $this->cursorCol = mb_strlen($this->lines[$this->cursorRow], 'UTF-8');
            }

            return true;
        }

        if ($key->is(KeyMsg::RIGHT)) {
            $lineLen = mb_strlen($this->lines[$this->cursorRow], 'UTF-8');
            if ($this->cursorCol < $lineLen) {
                ++$this->cursorCol;
            } elseif ($this->cursorRow < count($this->lines) - 1) {
                ++$this->cursorRow;
                $this->cursorCol = 0;
            }

            return true;
        }

        if ($key->is(KeyMsg::ENTER)) {
            $curLine = $this->lines[$this->cursorRow];
            $left = mb_substr($curLine, 0, $this->cursorCol, 'UTF-8');
            $right = mb_substr($curLine, $this->cursorCol, null, 'UTF-8');

            $this->lines[$this->cursorRow] = $left;
            array_splice($this->lines, $this->cursorRow + 1, 0, [$right]);
            ++$this->cursorRow;
            $this->cursorCol = 0;

            return true;
        }

        if ($key->is(KeyMsg::BACKSPACE)) {
            $curLine = $this->lines[$this->cursorRow];
            if ($this->cursorCol > 0) {
                $left = mb_substr($curLine, 0, $this->cursorCol - 1, 'UTF-8');
                $right = mb_substr($curLine, $this->cursorCol, null, 'UTF-8');
                $this->lines[$this->cursorRow] = $left.$right;
                --$this->cursorCol;
            } elseif ($this->cursorRow > 0) {
                $prevLine = $this->lines[$this->cursorRow - 1];
                $newCol = mb_strlen($prevLine, 'UTF-8');
                $this->lines[$this->cursorRow - 1] = $prevLine.$curLine;
                array_splice($this->lines, $this->cursorRow, 1);
                --$this->cursorRow;
                $this->cursorCol = $newCol;
            }

            return true;
        }

        if ('' !== $key->runes && !$key->ctrl && !$key->alt) {
            $curLine = $this->lines[$this->cursorRow];
            $left = mb_substr($curLine, 0, $this->cursorCol, 'UTF-8');
            $right = mb_substr($curLine, $this->cursorCol, null, 'UTF-8');
            $this->lines[$this->cursorRow] = $left.$key->runes.$right;
            $this->cursorCol += mb_strlen($key->runes, 'UTF-8');

            return true;
        }

        return false;
    }

    public function view(bool $focused, int $width): string
    {
        $reset = TokyoNight::reset();
        $labelColor = $focused ? TokyoNight::hexToAnsi(TokyoNight::ACTIVE_BORDER) : TokyoNight::hexToAnsi(TokyoNight::MUTED);
        $lbl = $labelColor.$this->label.$reset;

        $boxBorderColor = $focused ? TokyoNight::hexToAnsi(TokyoNight::ACTIVE_BORDER) : TokyoNight::hexToAnsi(TokyoNight::INACTIVE_BORDER);
        $boxBg = TokyoNight::hexToAnsi(TokyoNight::STATUS_BAR, true);

        $innerWidth = max(10, $width - 4);
        $topLine = $boxBorderColor.'┌'.str_repeat('─', $innerWidth).'┐'.$reset;
        $botLine = $boxBorderColor.'└'.str_repeat('─', $innerWidth).'┘'.$reset;

        $renderedRows = [];
        $totalLines = count($this->lines);

        // Keep cursorRow visible in window of $this->height lines
        $startRow = 0;
        if ($this->cursorRow >= $this->height) {
            $startRow = $this->cursorRow - $this->height + 1;
        }

        for ($i = 0; $i < $this->height; ++$i) {
            $actualRow = $startRow + $i;
            $lineContent = '';

            if ($actualRow < $totalLines) {
                $text = $this->lines[$actualRow];
                if ($focused && $actualRow === $this->cursorRow) {
                    $chars = mb_str_split($text, 1, 'UTF-8');
                    $len = count($chars);
                    for ($c = 0; $c <= $len; ++$c) {
                        $char = $chars[$c] ?? ' ';
                        if ($c === $this->cursorCol) {
                            $lineContent .= "\x1b[7m".$char."\x1b[27m";
                        } elseif ($c < $len) {
                            $lineContent .= TokyoNight::hexToAnsi(TokyoNight::FG).$char.$reset;
                        }
                    }
                } else {
                    $lineContent = TokyoNight::hexToAnsi(TokyoNight::FG).$text.$reset;
                }
            }

            $inner = ' '.$lineContent;
            $visLen = Ansi::width($inner);
            if ($visLen < $innerWidth) {
                $inner .= str_repeat(' ', $innerWidth - $visLen);
            }
            $renderedRows[] = $boxBorderColor.'│'.$reset.$boxBg.$inner.$reset.$boxBorderColor.'│'.$reset;
        }

        return $lbl."\n".$topLine."\n".implode("\n", $renderedRows)."\n".$botLine;
    }
}

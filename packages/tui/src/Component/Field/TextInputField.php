<?php

declare(strict_types=1);

namespace CodesSh\Tui\Component\Field;

use CodesSh\Tui\Event\KeyMsg;
use CodesSh\Tui\Style\Ansi;
use CodesSh\Tui\Style\TokyoNight;

final class TextInputField implements FormFieldInterface
{
    private string $id;
    private string $label;
    private string $value;
    private int $cursor;
    private string $placeholder;

    public function __construct(string $id, string $label, string $initialValue = '', string $placeholder = '')
    {
        $this->id = $id;
        $this->label = $label;
        $this->value = $initialValue;
        $this->placeholder = $placeholder;
        $this->cursor = mb_strlen($this->value, 'UTF-8');
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
        return $this->value;
    }

    public function setValue(mixed $val): void
    {
        $this->value = (string) $val;
        $this->cursor = mb_strlen($this->value, 'UTF-8');
    }

    public function handleKey(KeyMsg $key): bool
    {
        if ($key->is(KeyMsg::LEFT)) {
            if ($this->cursor > 0) {
                --$this->cursor;
            }

            return true;
        }

        if ($key->is(KeyMsg::RIGHT)) {
            $len = mb_strlen($this->value, 'UTF-8');
            if ($this->cursor < $len) {
                ++$this->cursor;
            }

            return true;
        }

        if ($key->is(KeyMsg::HOME)) {
            $this->cursor = 0;

            return true;
        }

        if ($key->is(KeyMsg::END)) {
            $this->cursor = mb_strlen($this->value, 'UTF-8');

            return true;
        }

        if ($key->is(KeyMsg::BACKSPACE)) {
            if ($this->cursor > 0) {
                $left = mb_substr($this->value, 0, $this->cursor - 1, 'UTF-8');
                $right = mb_substr($this->value, $this->cursor, null, 'UTF-8');
                $this->value = $left.$right;
                --$this->cursor;
            }

            return true;
        }

        if ($key->is(KeyMsg::DELETE)) {
            $len = mb_strlen($this->value, 'UTF-8');
            if ($this->cursor < $len) {
                $left = mb_substr($this->value, 0, $this->cursor, 'UTF-8');
                $right = mb_substr($this->value, $this->cursor + 1, null, 'UTF-8');
                $this->value = $left.$right;
            }

            return true;
        }

        // Typing printable character / runes
        if ('' !== $key->runes && !$key->ctrl && !$key->alt) {
            $left = mb_substr($this->value, 0, $this->cursor, 'UTF-8');
            $right = mb_substr($this->value, $this->cursor, null, 'UTF-8');
            $this->value = $left.$key->runes.$right;
            $this->cursor += mb_strlen($key->runes, 'UTF-8');

            return true;
        }

        return false;
    }

    public function view(bool $focused, int $width): string
    {
        $reset = TokyoNight::reset();
        $labelColor = $focused ? TokyoNight::hexToAnsi(TokyoNight::ACTIVE_BORDER) : TokyoNight::hexToAnsi(TokyoNight::MUTED);
        $lbl = $labelColor.$this->label.$reset;

        $content = '';
        if ('' === $this->value && '' !== $this->placeholder && !$focused) {
            $content = TokyoNight::hexToAnsi(TokyoNight::MUTED).$this->placeholder.$reset;
        } else {
            $chars = mb_str_split($this->value, 1, 'UTF-8');
            $len = count($chars);

            if ($focused) {
                for ($i = 0; $i <= $len; ++$i) {
                    $char = $chars[$i] ?? ' ';
                    if ($i === $this->cursor) {
                        // Inverted cursor
                        $content .= "\x1b[7m".$char."\x1b[27m";
                    } elseif ($i < $len) {
                        $content .= TokyoNight::hexToAnsi(TokyoNight::FG).$char.$reset;
                    }
                }
            } else {
                $content = TokyoNight::hexToAnsi(TokyoNight::FG).$this->value.$reset;
            }
        }

        $boxBorderColor = $focused ? TokyoNight::hexToAnsi(TokyoNight::ACTIVE_BORDER) : TokyoNight::hexToAnsi(TokyoNight::INACTIVE_BORDER);
        $boxBg = TokyoNight::hexToAnsi(TokyoNight::STATUS_BAR, true);

        // Render input field with border or bracket style
        $inner = ' '.$content;
        $innerWidth = max(10, $width - 4);
        $visLen = Ansi::width($inner);
        if ($visLen < $innerWidth) {
            $inner .= str_repeat(' ', $innerWidth - $visLen);
        }

        $inputLine = $boxBorderColor.'│'.$reset.$boxBg.$inner.$reset.$boxBorderColor.'│'.$reset;
        $topLine = $boxBorderColor.'┌'.str_repeat('─', $innerWidth).'┐'.$reset;
        $botLine = $boxBorderColor.'└'.str_repeat('─', $innerWidth).'┘'.$reset;

        return $lbl."\n".$topLine."\n".$inputLine."\n".$botLine;
    }
}

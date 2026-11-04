<?php

declare(strict_types=1);

namespace CodesSh\Tui\Component\Field;

use CodesSh\Tui\Event\KeyMsg;
use CodesSh\Tui\Style\TokyoNight;

final class ToggleField implements FormFieldInterface
{
    private string $id;
    private string $label;
    private bool $checked;
    private string $description;

    public function __construct(string $id, string $label, bool $initialValue = false, string $description = '')
    {
        $this->id = $id;
        $this->label = $label;
        $this->checked = $initialValue;
        $this->description = $description;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function getValue(): bool
    {
        return $this->checked;
    }

    public function setValue(mixed $val): void
    {
        $this->checked = (bool) $val;
    }

    public function handleKey(KeyMsg $key): bool
    {
        // Space, x, Enter, Left, Right toggle the checkbox matching Go form
        if (' ' === $key->key || ' ' === $key->runes || $key->is('x') || $key->is(KeyMsg::ENTER) || $key->is(KeyMsg::LEFT) || $key->is(KeyMsg::RIGHT)) {
            $this->checked = !$this->checked;

            return true;
        }

        return false;
    }

    public function view(bool $focused, int $width): string
    {
        $reset = TokyoNight::reset();
        $labelColor = $focused ? TokyoNight::hexToAnsi(TokyoNight::ACTIVE_BORDER) : TokyoNight::hexToAnsi(TokyoNight::MUTED);
        $checkColor = $this->checked ? TokyoNight::hexToAnsi(TokyoNight::SUCCESS) : TokyoNight::hexToAnsi(TokyoNight::MUTED);
        $box = $this->checked ? '[x]' : '[ ]';

        $focusIndicator = $focused ? TokyoNight::hexToAnsi(TokyoNight::ACTIVE_BORDER).'▶ '.$reset : '  ';

        $line = $focusIndicator.$checkColor.$box.$reset.' '.$labelColor.$this->label.$reset;
        if ('' !== $this->description) {
            $line .= ' '.TokyoNight::hexToAnsi(TokyoNight::MUTED).'('.$this->description.')'.$reset;
        }

        return $line;
    }
}

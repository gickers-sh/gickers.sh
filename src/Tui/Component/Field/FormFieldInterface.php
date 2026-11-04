<?php

declare(strict_types=1);

namespace App\Tui\Component\Field;

use App\Tui\Event\KeyMsg;

interface FormFieldInterface
{
    public function id(): string;

    public function label(): string;

    /**
     * Renders the field view depending on focused state and available width.
     */
    public function view(bool $focused, int $width): string;

    /**
     * Handles keyboard events when the field has focus.
     * Returns true if the key was consumed/handled, false otherwise.
     */
    public function handleKey(KeyMsg $key): bool;

    public function getValue(): mixed;

    public function setValue(mixed $val): void;
}

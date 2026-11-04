<?php

declare(strict_types=1);

namespace App\Tui\Component;

use App\Tui\Component\Field\FormFieldInterface;
use App\Tui\Event\InputEvent;
use App\Tui\Event\KeyMsg;
use App\Tui\Style\Ansi;
use App\Tui\Style\HorizontalAlign;
use App\Tui\Style\Layout;
use App\Tui\Style\TokyoNight;
use App\Tui\Style\VerticalAlign;

final class FormComponent implements ComponentInterface
{
    private string $title;
    private string $subtitle;
    /** @var FormFieldInterface[] */
    private array $fields;
    private int $focusedIndex = 0;

    /**
     * @param FormFieldInterface[]|string $arg2 Fields array or subtitle string
     * @param FormFieldInterface[]|string $arg3 Subtitle string or fields array
     */
    public function __construct(string $title, array|string $arg2 = [], array|string $arg3 = '')
    {
        $this->title = $title;
        if (is_array($arg2)) {
            $this->fields = array_values($arg2);
            $this->subtitle = is_string($arg3) ? $arg3 : '';
        } else {
            $this->subtitle = $arg2;
            $this->fields = is_array($arg3) ? array_values($arg3) : [];
        }
    }

    public function init(): void
    {
        $this->focusedIndex = 0;
    }

    public function addField(FormFieldInterface $field): self
    {
        $this->fields[] = $field;
        return $this;
    }

    /**
     * @return FormFieldInterface[]
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    public function getField(string $id): ?FormFieldInterface
    {
        foreach ($this->fields as $field) {
            if ($field->id() === $id) {
                return $field;
            }
        }
        return null;
    }

    public function getFocusedIndex(): int
    {
        return $this->focusedIndex;
    }

    public function setFocusedIndex(int $index): void
    {
        if ($index >= 0 && $index < count($this->fields)) {
            $this->focusedIndex = $index;
        }
    }

    /**
     * Returns an associative array of all field id => value.
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $data = [];
        foreach ($this->fields as $field) {
            $data[$field->id()] = $field->getValue();
        }
        return $data;
    }

    public function getFieldValue(string $id): mixed
    {
        foreach ($this->fields as $field) {
            if ($field->id() === $id) {
                return $field->getValue();
            }
        }
        return null;
    }

    public function update(InputEvent $event): ?string
    {
        if (!$event instanceof KeyMsg) {
            return null;
        }

        // Global shortcuts for the form
        if ($event->is('ctrl+s')) {
            return 'submit';
        }

        // If single field and Enter pressed, or on last button/toggle
        if ($event->is(KeyMsg::ENTER) && count($this->fields) === 1) {
            return 'submit';
        }
        if ($event->is(KeyMsg::ESC)) {
            return 'cancel';
        }

        if ($event->is(KeyMsg::TAB) || $event->is(KeyMsg::DOWN)) {
            if (!empty($this->fields)) {
                $this->focusedIndex = ($this->focusedIndex + 1) % count($this->fields);
            }
            return null;
        }

        if ($event->is(KeyMsg::BACKTAB) || $event->is(KeyMsg::UP)) {
            if (!empty($this->fields)) {
                $this->focusedIndex = ($this->focusedIndex - 1 + count($this->fields)) % count($this->fields);
            }
            return null;
        }

        // Delegate to active field
        if (isset($this->fields[$this->focusedIndex])) {
            $this->fields[$this->focusedIndex]->handleKey($event);
        }

        return null;
    }

    public function view(int $width, int $height): string
    {
        $modalWidth = min(80, max(40, $width - 8));
        $reset = TokyoNight::reset();

        $borderColor = TokyoNight::hexToAnsi(TokyoNight::ACTIVE_BORDER);
        $headerColor = TokyoNight::hexToAnsi(TokyoNight::CYAN);
        $helpColor = TokyoNight::hexToAnsi(TokyoNight::MUTED);

        $header = "\x1b[1m" . $headerColor . $this->title . $reset;
        if ($this->subtitle !== '') {
            $header .= ' ' . TokyoNight::hexToAnsi(TokyoNight::MUTED) . '(' . $this->subtitle . ')' . $reset;
        }

        $lines = [];
        $lines[] = $header;
        $lines[] = '';

        foreach ($this->fields as $idx => $field) {
            $isFocused = ($idx === $this->focusedIndex);
            $fieldView = $field->view($isFocused, $modalWidth - 6);
            $fieldLines = explode("\n", $fieldView);
            foreach ($fieldLines as $fl) {
                $lines[] = $fl;
            }
            $lines[] = '';
        }

        $helpText = $helpColor . 'ctrl+s submit • esc cancel • tab next' . $reset;
        $lines[] = $helpText;

        // Wrap into a box with rounded borders
        $innerBoxWidth = $modalWidth - 2;
        $topBorder = $borderColor . '╭─ ' . $header . ' ' . str_repeat('─', max(0, $innerBoxWidth - Ansi::width($header) - 4)) . '╮' . $reset;
        $bottomBorder = $borderColor . '╰' . str_repeat('─', $innerBoxWidth) . '╯' . $reset;

        $boxRows = [];
        $boxRows[] = $topBorder;

        // Skip title in content body since it is in top border
        array_shift($lines);
        if (!empty($lines) && $lines[0] === '') {
            array_shift($lines);
        }

        foreach ($lines as $line) {
            $w = Ansi::width($line);
            $padding = max(0, $innerBoxWidth - $w - 2);
            $boxRows[] = $borderColor . '│ ' . $reset . $line . str_repeat(' ', $padding) . $borderColor . ' │' . $reset;
        }

        $boxRows[] = $bottomBorder;
        $renderedBox = implode("\n", $boxRows);

        return Layout::place($width, $height, HorizontalAlign::Center, VerticalAlign::Middle, $renderedBox);
    }
}

<?php

declare(strict_types=1);

namespace App\UI\Tui\Render;

use App\UI\Tui\Input\Keys;

/**
 * Two-field commit prompt form (summary + optional description).
 */
final class CommitForm
{
    private string $summary = '';
    private string $description = '';
    private int $field = 0; // 0 = summary, 1 = description
    private ?string $author = null;

    public function __construct(string $summary = '', ?string $author = null)
    {
        $this->summary = $summary;
        $this->author = $author;
    }

    public function reset(string $summary): void
    {
        $this->summary = $summary;
        $this->description = '';
        $this->field = 0;
    }

    public function field(): int
    {
        return $this->field;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function setAuthor(?string $author): void
    {
        $this->author = $author;
    }

    public function message(): string
    {
        $summary = trim($this->summary);
        $desc = trim($this->description);

        return '' !== $desc ? $summary . "\n\n" . $desc : $summary;
    }

    public function handleKey(string $seq): bool
    {
        if (InlineEditor::isCtrlS($seq)) {
            return false;
        }

        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if (Keys::ESCAPE === $tok || Keys::ENTER === $tok) {
            return false;
        }

        if (Keys::TAB === $tok || Keys::DOWN === $tok || Keys::BACKTAB === $tok || Keys::UP === $tok) {
            $this->field = 0 === $this->field ? 1 : 0;

            return true;
        }

        if (Keys::BACKSPACE === $tok) {
            if (0 === $this->field) {
                $this->summary = mb_substr($this->summary, 0, -1);
            } else {
                $this->description = mb_substr($this->description, 0, -1);
            }

            return true;
        }

        if (null !== $txt) {
            if (0 === $this->field) {
                $this->summary .= $txt;
            } else {
                $this->description .= $txt;
            }

            return true;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public function paint(int $cols, int $rows, ?string $author = null, ?string $file = null): array
    {
        $title = 'Commit changes';
        $boxWidth = min(60, max(40, $cols - 4));

        $sumActive = 0 === $this->field;
        $descActive = 1 === $this->field;

        $authorDisplay = $author ?? $this->author ?? 'anonymous <anonymous@noreply.codes.sh>';

        $lines = [''];
        if (null !== $file && '' !== $file) {
            $lines[] = '  ' . Theme::style('File: ', Theme::MUTED) . Theme::style($file, Theme::FG, bold: true);
            $lines[] = '';
        }
        $lines[] = '  ' . ($sumActive ? Theme::style('Summary', Theme::BLUE, bold: true) : Theme::style('Summary', Theme::MUTED));
        $lines[] = '  > ' . $this->summary . ($sumActive ? '▏' : '');
        $lines[] = '';
        $lines[] = '  ' . ($descActive ? Theme::style('Description (optional)', Theme::BLUE, bold: true) : Theme::style('Description (optional)', Theme::MUTED));
        $lines[] = '  > ' . $this->description . ($descActive ? '▏' : '');
        $lines[] = '';
        $lines[] = '  ' . Theme::style('Author:    ', Theme::MUTED) . Theme::style($authorDisplay, Theme::FG);
        $lines[] = '';
        $lines[] = '  ' . Theme::style('Tab: switch field · Enter/Ctrl+S: commit · Esc: cancel', Theme::MUTED, italic: true);

        $box = Screen::box($title, $lines, $boxWidth);

        return Screen::centerBlock($box, $cols, $rows);
    }
}

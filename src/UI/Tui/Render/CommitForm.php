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
    private Editor $descEditor;
    private int $field = 0; // 0 = summary, 1 = description
    private ?string $author = null;

    public function __construct(string $summary = '', ?string $author = null)
    {
        $this->summary = $summary;
        $this->author = $author;
        $this->descEditor = new Editor();
    }

    public function reset(string $summary): void
    {
        $this->summary = $summary;
        $this->descEditor = new Editor();
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
        return trim($this->descEditor->value());
    }

    public function setAuthor(?string $author): void
    {
        $this->author = $author;
    }

    public function message(): string
    {
        $summary = trim($this->summary);
        $desc = trim($this->descEditor->value());

        return '' !== $desc ? $summary."\n\n".$desc : $summary;
    }

    public function handleKey(string $seq): bool
    {
        if (Editor::isCtrlS($seq)) {
            return false;
        }

        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if (Keys::ESCAPE === $tok) {
            return false;
        }

        if (0 === $this->field && Keys::ENTER === $tok) {
            $this->field = 1;

            return true;
        }

        if (Keys::TAB === $tok || Keys::BACKTAB === $tok) {
            $this->field = 0 === $this->field ? 1 : 0;

            return true;
        }

        if (0 === $this->field) {
            if (Keys::DOWN === $tok) {
                $this->field = 1;

                return true;
            }
            if (Keys::BACKSPACE === $tok) {
                $this->summary = mb_substr($this->summary, 0, -1);

                return true;
            }
            if (null !== $txt) {
                $this->summary .= $txt;

                return true;
            }

            return true;
        }

        // Field 1: Description Editor
        if (Keys::UP === $tok && 0 === $this->descEditor->line()) {
            $this->field = 0;

            return true;
        }

        return $this->descEditor->handleKey($seq);
    }

    /**
     * @return list<string>
     */
    public function paint(int $cols, int $rows, ?string $author = null, ?string $file = null): array
    {
        $title = 'Commit changes';
        $boxWidth = min(60, max(40, $cols - 4));
        $innerWidth = max(20, $boxWidth - 4);
        $sumActive = 0 === $this->field;
        $descActive = 1 === $this->field;

        $authorDisplay = $author ?? $this->author ?? 'anonymous <anonymous@noreply.codes.sh>';

        $lines = [''];
        if (null !== $file && '' !== $file) {
            $lines[] = '  '.Theme::style('File: ', Theme::MUTED).Theme::style($file, Theme::FG, bold: true);
            $lines[] = '';
        }
        $lines[] = '  '.($sumActive ? Theme::style('Summary', Theme::BLUE, bold: true) : Theme::style('Summary', Theme::MUTED));
        $prompt = '> ';
        $availSumWidth = max(10, $innerWidth - 4);
        $sumVal = Screen::truncate($this->summary, $availSumWidth);
        $lines[] = '  '.$prompt.$sumVal.($sumActive ? '▏' : '');
        $lines[] = '';
        $lines[] = '  '.($descActive ? Theme::style('Description (optional)', Theme::BLUE, bold: true) : Theme::style('Description (optional)', Theme::MUTED));
        $descEditorWidth = max(15, $innerWidth - 2);
        foreach ($this->descEditor->paintLines($descEditorWidth, 4, $descActive) as $dl) {
            $lines[] = '  '.$dl;
        }
        $lines[] = '  '.Theme::style('Author:    ', Theme::MUTED).Theme::style($authorDisplay, Theme::FG);
        $lines[] = '';
        $lines[] = '  '.Theme::style('Tab/↑/↓: field · Ctrl+S: commit · Esc: cancel', Theme::MUTED, italic: true);

        $box = Screen::box($title, $lines, $boxWidth);

        return Screen::centerBlock($box, $cols, $rows);
    }
}

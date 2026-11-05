<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Tui;

use App\UI\Tui\Input\Keys;
use App\UI\Tui\Render\InlineEditor;
use App\UI\Tui\Render\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Pins the Markdown live-preview routing ported from Go Soft Serve
 * `pkg/ui/components/editor/editor.go`: inactive lines are rendered, the cursor
 * line stays raw source, fenced code is never Markdown-rendered, preview is
 * Markdown-only, and Ctrl+P cycles inline -> full -> off -> inline.
 */
final class InlineEditorPreviewTest extends TestCase
{
    private function renderer(): FakeMarkdownRenderer
    {
        return new FakeMarkdownRenderer();
    }

    /**
     * @return list<string>
     */
    private function plainRows(InlineEditor $ed, int $cols = 60, int $rows = 10): array
    {
        $out = [];
        foreach ($ed->paint('README.md', $cols, $rows) as $row) {
            $out[] = preg_replace('/\e\[[0-9;]*m/', '', $row) ?? $row;
        }

        return $out;
    }

    public function testInlineModeRendersNonCursorLinesAndLeavesCursorLineRaw(): void
    {
        $renderer = $this->renderer();
        $ed = new InlineEditor();
        $ed->setContent("# Title\nsome *text*\ntail");
        $ed->enableMarkdownPreview($renderer, 'README.md');
        $ed->moveTo(1, 2); // cursor on "some *text*"

        self::assertTrue($ed->previewSupported());
        self::assertSame('inline', $ed->previewMode());
        self::assertTrue($ed->isLivePreview());
        self::assertFalse($ed->isFullPreview());

        $rows = $this->plainRows($ed);
        self::assertStringContainsString('R<# Title>', $rows[1]);     // inactive -> rendered
        self::assertStringContainsString('some *text*', $rows[2]);    // cursor -> raw source
        self::assertStringNotContainsString('R<some', $rows[2]);
        self::assertStringContainsString('R<tail>', $rows[3]);        // inactive -> rendered
    }

    public function testFencedCodeIsNotMarkdownRendered(): void
    {
        $renderer = $this->renderer();
        $ed = new InlineEditor();
        $ed->setContent("intro\n```\ncode line\n```\nend");
        $ed->enableMarkdownPreview($renderer, 'README.md');
        $ed->moveTo(0, 0); // cursor on "intro" so the block is all inactive

        $rows = $this->plainRows($ed);
        self::assertStringContainsString('```', $rows[2]);            // fence delimiter raw
        self::assertStringNotContainsString('R<', $rows[2]);
        self::assertStringContainsString('code line', $rows[3]);      // code raw
        self::assertStringNotContainsString('R<code', $rows[3]);
        self::assertStringContainsString('R<end>', $rows[5]);         // normal line rendered
    }

    public function testListLinesRenderedAndStayLineAligned(): void
    {
        $renderer = $this->renderer();
        $ed = new InlineEditor();
        $ed->setContent("header\n\n- item 1\n- item 2\n\nfooter");
        $ed->enableMarkdownPreview($renderer, 'README.md');
        $ed->moveTo(0, 0); // cursor on header, so items 1 and 2 are inactive

        $rows = $this->plainRows($ed);
        self::assertContains('- item 1', $renderer->renderedBlocks);
        self::assertContains('- item 2', $renderer->renderedBlocks);

        // stays line-aligned with gutter
        self::assertStringContainsString('1▶ header', $rows[1]);
        self::assertStringContainsString('3│ R<- item 1>', $rows[3]);
        self::assertStringContainsString('4│ R<- item 2>', $rows[4]);
        self::assertStringContainsString('6│ R<footer>', $rows[6]);

        // When cursor is inside the list, cursor line stays raw while other lines render individually
        $ed->moveTo(2, 2); // cursor on "- item 1" (line index 2)
        $rowsWithCursor = $this->plainRows($ed);
        self::assertStringContainsString('3▶ - item 1', $rowsWithCursor[3]);
        self::assertStringContainsString('4│ R<- item 2>', $rowsWithCursor[4]);
    }

    public function testCyclePreviewAndFullModeReadOnly(): void
    {
        $renderer = $this->renderer();
        $ed = new InlineEditor();
        $ed->setContent("# Title\nbody line");
        $ed->enableMarkdownPreview($renderer, 'README.md');
        $ed->moveTo(1, 0);

        // 1. Initial mode: inline
        self::assertSame('inline', $ed->previewMode());
        self::assertTrue($ed->isLivePreview());
        self::assertFalse($ed->isFullPreview());
        self::assertStringContainsString('R<# Title>', $this->plainRows($ed)[1]);

        // 2. Cycle to full mode via Ctrl+P (Keys::CTRL_P is \x10)
        self::assertTrue($ed->handleKey("\x10"));
        self::assertSame('full', $ed->previewMode());
        self::assertFalse($ed->isLivePreview());
        self::assertTrue($ed->isFullPreview());

        // Full mode renders full document, no gutter
        $fullRows = $this->plainRows($ed);
        self::assertStringContainsString('R<# Title>', $fullRows[1]);
        self::assertStringContainsString('R<body line>', $fullRows[2]);
        self::assertStringNotContainsString('1│', $fullRows[1]);
        self::assertStringNotContainsString('1▶', $fullRows[1]);

        // Full mode is read-only: printable, ENTER, BACKSPACE do not mutate buffer
        $initialValue = $ed->value();
        self::assertTrue($ed->handleKey('x'));
        self::assertSame($initialValue, $ed->value());
        self::assertTrue($ed->handleKey("\r")); // ENTER
        self::assertSame($initialValue, $ed->value());
        self::assertTrue($ed->handleKey("\x7f")); // BACKSPACE
        self::assertSame($initialValue, $ed->value());

        // 3. Cycle to off mode
        self::assertTrue($ed->handleKey("\x10"));
        self::assertSame('off', $ed->previewMode());
        self::assertFalse($ed->isLivePreview());
        self::assertFalse($ed->isFullPreview());

        $offRows = $this->plainRows($ed);
        self::assertStringContainsString('# Title', $offRows[1]);
        self::assertStringNotContainsString('R<', $offRows[1]);

        // 4. Cycle back to inline mode
        self::assertTrue($ed->handleKey("\x10"));
        self::assertSame('inline', $ed->previewMode());
        self::assertTrue($ed->isLivePreview());
        self::assertFalse($ed->isFullPreview());
    }

    public function testPreviewUnsupportedForNonMarkdown(): void
    {
        $renderer = $this->renderer();
        $ed = new InlineEditor();
        $ed->setContent("package main");
        $ed->enableMarkdownPreview($renderer, 'main.go');

        self::assertFalse($ed->previewSupported());
        self::assertSame('off', $ed->previewMode());
        self::assertFalse($ed->isLivePreview());
        self::assertFalse($ed->isFullPreview());

        // cyclePreview is a no-op
        $ed->cyclePreview();
        self::assertSame('off', $ed->previewMode());

        $rows = $this->plainRows($ed);
        self::assertStringContainsString('package main', $rows[1]);
        self::assertStringNotContainsString('R<', $rows[1]);
    }

    public function testUnavailableRendererFallsBackToSource(): void
    {
        $renderer = new class implements MarkdownRenderer {
            public function isAvailable(): bool
            {
                return false;
            }

            public function renderDocument(string $filename, string $content, int $width, array $options = []): string
            {
                throw new \RuntimeException('Unavailable');
            }

            public function renderSnippet(string $filename, string $snippet, int $width, string $docDefs = ''): string
            {
                throw new \RuntimeException('Unavailable');
            }
        };

        $ed = new InlineEditor();
        $ed->setContent("# Hello\nworld");
        $ed->enableMarkdownPreview($renderer, 'README.md');

        $rows = $this->plainRows($ed);
        self::assertStringContainsString('# Hello', $rows[1]);
        self::assertStringContainsString('world', $rows[2]);
    }
}

final class FakeMarkdownRenderer implements MarkdownRenderer
{
    /** @var list<string> */
    public array $renderedBlocks = [];

    public function isAvailable(): bool
    {
        return true;
    }

    public function renderDocument(string $filename, string $content, int $width, array $options = []): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $out[] = 'R<' . $line . '>';
        }

        return implode("\n", $out);
    }

    public function renderSnippet(string $filename, string $snippet, int $width, string $docDefs = ''): string
    {
        $this->renderedBlocks[] = $snippet;

        return 'R<' . $snippet . '>';
    }
}

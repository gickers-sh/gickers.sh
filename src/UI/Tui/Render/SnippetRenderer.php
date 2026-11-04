<?php

declare(strict_types=1);

namespace App\UI\Tui\Render;

/**
 * Renders one line of source as styled ANSI for the editor live preview.
 *
 * Relocated from the deleted `packages/tui` (`CodesSh\Tui\Component\SnippetRenderer`)
 * into the UI layer so the TUI no longer depends on the retired package.
 */
interface SnippetRenderer
{
    /**
     * Returns an empty string when the snippet cannot be rendered.
     */
    public function renderSnippet(string $filename, string $snippet, int $width): string;
}

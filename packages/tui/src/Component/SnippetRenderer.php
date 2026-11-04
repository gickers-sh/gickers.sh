<?php

declare(strict_types=1);

namespace CodesSh\Tui\Component;

/**
 * Renders one line of source as styled ANSI for the editor live preview.
 */
interface SnippetRenderer
{
    /**
     * Returns an empty string when the snippet cannot be rendered.
     */
    public function renderSnippet(string $filename, string $snippet, int $width): string;
}

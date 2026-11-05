<?php

declare(strict_types=1);

namespace App\UI\Tui\Render;

interface MarkdownRenderer
{
    /**
     * @param array<string, mixed> $options
     */
    public function renderDocument(string $filename, string $content, int $width, array $options = []): string;

    public function renderSnippet(string $filename, string $snippet, int $width, string $docDefs = ''): string;

    public function isAvailable(): bool;
}

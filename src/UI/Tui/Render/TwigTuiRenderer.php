<?php

declare(strict_types=1);

namespace App\UI\Tui\Render;

use Termstrap\Termstrap;
use Twig\Environment;

/**
 * Service orchestrating Twig templates rendering into ANSI strings via Termstrap.
 */
final class TwigTuiRenderer
{
    /** @var array<string, list<string>> */
    private array $renderCache = [];
    private const CACHE_LIMIT = 300;

    public function __construct(
        private readonly Environment $twig,
        private readonly ?TermstrapRenderer $termstrapRenderer = null,
    ) {
    }

    public function termstrapRenderer(): ?TermstrapRenderer
    {
        return $this->termstrapRenderer;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(string $template, array $context = [], int $width = 80): string
    {
        return implode("\n", $this->renderLines($template, $context, $width));
    }

    /**
     * Renders a Twig template (HTML + CSS via Termstrap, or direct ANSI lines).
     *
     * @param array<string, mixed> $context
     * @return list<string>
     */
    public function renderLines(string $template, array $context = [], int $width = 80): array
    {
        $context['width'] = $width;
        $context['terminal_width'] = $width;

        $cacheKey = $template . '|' . $width . '|' . md5(serialize($context));
        if (isset($this->renderCache[$cacheKey])) {
            return $this->renderCache[$cacheKey];
        }

        $raw = $this->twig->render($template, $context);

        if (str_contains($raw, '<') && str_contains($raw, '>')) {
            $ansi = $this->renderHtml($raw, $width);
            $rawLines = preg_split('/\r\n|\r|\n/', rtrim($ansi, "\r\n")) ?: [];
        } else {
            $decoded = html_entity_decode($raw, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
            $rawLines = preg_split('/\r\n|\r|\n/', $decoded) ?: [];
        }

        $lines = [];
        foreach ($rawLines as $line) {
            if ('' === trim($line) && !str_contains($raw, '<')) {
                continue;
            }
            $lines[] = \App\UI\Tui\Format\Ansi::fit($line, $width);
        }

        if (\count($this->renderCache) >= self::CACHE_LIMIT) {
            $this->renderCache = \array_slice($this->renderCache, intdiv(self::CACHE_LIMIT, 2), null, true);
        }
        $this->renderCache[$cacheKey] = $lines;

        return $lines;
    }

    /**
     * Render raw HTML snippet directly via Termstrap with the shared TUI theme.
     *
     * @param array<string, mixed> $options
     */
    public function renderHtml(string $html, int $width = 80, array $options = []): string
    {
        $mergedOptions = array_merge([
            'width' => $width,
            'theme' => 'none',
            'wrap_code' => true,
            'stylesheets' => [
                (string) file_get_contents(\dirname(__DIR__, 4) . '/assets/tui/theme.css'),
            ],
        ], $options);

        return Termstrap::toAnsi($html, $mergedOptions);
    }
}

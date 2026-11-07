<?php

declare(strict_types=1);

namespace App\UI\Tui\Render;

use Symfony\Component\Process\Process;
use Termstrap\Termstrap;

final class TermstrapRenderer implements MarkdownRenderer
{
    public const THEME_OVERRIDE_CSS = <<<'CSS'
h1, h2, h3, h4, h5, h6 {
  margin-top: 0 !important;
  margin-bottom: 0 !important;
  padding-top: 0 !important;
  padding-bottom: 0 !important;
}
pre {
  margin-top: 0 !important;
  margin-bottom: 0 !important;
}
CSS;

    private ?Termstrap $termstrap = null;
    private bool $termstrapAvailable = false;

    private ?string $libraryPath;

    public function __construct(?string $libraryPath = null)
    {
        $this->libraryPath = $libraryPath;
        try {
            $this->termstrap = new Termstrap($libraryPath);
            $this->termstrapAvailable = true;
        } catch (\Throwable) {
            $this->termstrapAvailable = false;
        }
    }

    public function isAvailable(): bool
    {
        return $this->termstrapAvailable;
    }

    /**
     * Renders a full document (Markdown, CSV, TSV, HTML) into ANSI terminal output.
     *
     * @param array<string, mixed> $options Additional options (step, root_path, theme, etc.)
     */
    public function renderDocument(string $filename, string $content, int $width, array $options = []): string
    {
        if ($width <= 0) {
            $width = 80;
        }

        $ext = $this->normalizeExt($filename);
        $isMarkdown = false;
        $input = $content;

        switch ($ext) {
            case '.csv':
                $input = $this->csvToHtml($content, ',');
                break;
            case '.tsv':
                $input = $this->csvToHtml($content, "\t");
                break;
            case '.html':
            case '.htm':
                $input = $content;
                break;
            case '.gif':
                $input = '<img src="'.htmlspecialchars($filename, \ENT_QUOTES, 'UTF-8').'" alt="Animated GIF" />';
                break;
            default:
                $input = $content;
                $isMarkdown = true;
                break;
        }

        if (!$this->termstrapAvailable || null === $this->termstrap) {
            return $this->fallbackRender($input, $width);
        }

        $termstrapOptions = [
            'width' => $width,
            'theme' => $options['theme'] ?? 'tokyonight',
            'link_mode' => $options['link_mode'] ?? 'osc8',
            'image_mode' => $options['image_mode'] ?? 'smart',
            'disable_animated_images' => true,
            'stylesheets' => [self::THEME_OVERRIDE_CSS],
            'markdown' => $isMarkdown,
        ];

        if (isset($options['step'])) {
            $termstrapOptions['step'] = (int) $options['step'];
        }
        if (isset($options['root_path'])) {
            $termstrapOptions['root_path'] = (string) $options['root_path'];
        }

        // Merge any other passed options
        foreach ($options as $key => $val) {
            if (!isset($termstrapOptions[$key])) {
                $termstrapOptions[$key] = $val;
            }
        }

        try {
            return $this->termstrap->render($input, $termstrapOptions);
        } catch (\Throwable) {
            return $this->fallbackRender($input, $width);
        }
    }

    /**
     * Starts a non-blocking PHP subprocess that runs {@see renderDocument()} so heavy FFI
     * rendering (remote badges/images, large documents) never blocks the TUI event loop.
     *
     * @param array<string, mixed> $options
     */
    public function startRenderDocumentProcess(string $filename, string $content, int $width, array $options = []): Process
    {
        $autoload = \dirname(__DIR__, 4).'/vendor/autoload.php';
        $script = 'require $argv[1];'
            .'$payload = json_decode(stream_get_contents(STDIN), true);'
            .'if (!is_array($payload)) { exit(1); }'
            .'$r = new \App\UI\Tui\Render\TermstrapRenderer($payload["libraryPath"] ?? null);'
            .'fwrite(STDOUT, $r->renderDocument((string) $payload["filename"], (string) $payload["content"], (int) $payload["width"], (array) ($payload["options"] ?? [])));';

        $payload = json_encode([
            'libraryPath' => $this->libraryPath,
            'filename' => $filename,
            'content' => $content,
            'width' => $width,
            'options' => $options,
        ], \JSON_THROW_ON_ERROR);

        $process = new Process([\PHP_BINARY, '-r', $script, $autoload]);
        $process->setInput($payload);
        $process->start();

        return $process;
    }

    /**
     * Starts a non-blocking PHP subprocess that renders a batch of snippets via {@see renderSnippet()}
     * and outputs a JSON object mapping each key to its rendered ANSI string.
     *
     * @param array<string, string> $snippets key => raw snippet text
     */
    public function startRenderSnippetsBatchProcess(string $filename, array $snippets, int $width, string $docDefs = ''): Process
    {
        $autoload = \dirname(__DIR__, 4).'/vendor/autoload.php';
        $script = 'require $argv[1];'
            .'$payload = json_decode(stream_get_contents(STDIN), true);'
            .'if (!is_array($payload)) { exit(1); }'
            .'$r = new \App\UI\Tui\Render\TermstrapRenderer($payload["libraryPath"] ?? null);'
            .'$out = [];'
            .'foreach (($payload["snippets"] ?? []) as $k => $line) {'
            .'    $out[$k] = $r->renderSnippet((string) $payload["filename"], (string) $line, (int) $payload["width"], (string) ($payload["docDefs"] ?? ""));'
            .'}'
            .'fwrite(STDOUT, json_encode($out, JSON_THROW_ON_ERROR));';

        $payload = json_encode([
            'libraryPath' => $this->libraryPath,
            'filename' => $filename,
            'snippets' => $snippets,
            'width' => $width,
            'docDefs' => $docDefs,
        ], \JSON_THROW_ON_ERROR);

        $process = new Process([\PHP_BINARY, '-r', $script, $autoload]);
        $process->setInput($payload);
        $process->start();

        return $process;
    }

    /**
     * Renders a single line / snippet for live preview in the editor, matching
     * Go Soft Serve `pkg/ui/render/renderer.go:RenderSnippet`.
     */
    public function renderSnippet(string $filename, string $snippet, int $width, string $docDefs = ''): string
    {
        $trimmed = trim($snippet);
        if ('' === $trimmed) {
            return '';
        }

        $leftTrimmed = ltrim($snippet, " \t");
        $leadIndent = substr($snippet, 0, strlen($snippet) - strlen($leftTrimmed));
        $content = $leftTrimmed;

        $renderInput = $content;
        if ('' !== $docDefs && str_contains($content, '][')) {
            $renderInput = $content."\n\n".$docDefs;
        }

        $ext = $this->normalizeExt($filename);
        $isMarkdown = false;
        $input = $renderInput;

        switch ($ext) {
            case '.csv':
                $input = $this->csvToHtml($renderInput, ',');
                break;
            case '.tsv':
                $input = $this->csvToHtml($renderInput, "\t");
                break;
            case '.html':
            case '.htm':
                $input = $renderInput;
                break;
            default:
                $input = $renderInput;
                $isMarkdown = true;
                break;
        }

        if ($width <= 0) {
            $width = 80;
        }

        if (!$this->termstrapAvailable || null === $this->termstrap) {
            return $leadIndent.$this->fallbackRender($input, $width);
        }

        $termstrapOptions = [
            'width' => $width,
            'theme' => 'tokyonight',
            'link_mode' => 'osc8',
            'image_mode' => 'smart',
            'disable_animated_images' => true,
            'stylesheets' => [self::THEME_OVERRIDE_CSS],
            'markdown' => $isMarkdown,
        ];

        try {
            $out = $this->termstrap->render($input, $termstrapOptions);
            $lines = explode("\n", $out);
            $target = '';
            foreach ($lines as $line) {
                $stripped = preg_replace('/\e\[[0-9;]*m/', '', $line) ?? $line;
                if ('' !== trim($stripped)) {
                    $target = rtrim($line, ' ');
                    break;
                }
            }
            if ('' === $target) {
                if (str_starts_with($trimmed, '>')) {
                    $target = "\e[38;5;240m│\e[0m ";
                } else {
                    $target = $content;
                }
            }

            return $leadIndent.$target;
        } catch (\Throwable) {
            return $leadIndent.$this->fallbackRender($input, $width);
        }
    }

    /**
     * Converts CSV/TSV content into an HTML table with Bootstrap styling.
     */
    public function csvToHtml(string $csvContent, string $delimiter = ','): string
    {
        if ('' === trim($csvContent)) {
            return '';
        }

        $lines = explode("\n", $csvContent);
        $rows = [];
        foreach ($lines as $line) {
            if ('' === $line && count($rows) > 0) {
                continue;
            }
            $record = str_getcsv($line, $delimiter, '"', '\\');
            $rows[] = $record;
        }

        if (empty($rows)) {
            return '';
        }

        $html = '<table class="table table-striped table-hover table-sm">';
        $html .= '<thead><tr>';
        foreach ($rows[0] as $col) {
            $html .= '<th>'.htmlspecialchars((string) $col, \ENT_QUOTES, 'UTF-8').'</th>';
        }
        $html .= '</tr></thead>';

        if (count($rows) > 1) {
            $html .= '<tbody>';
            for ($i = 1; $i < count($rows); ++$i) {
                $html .= '<tr>';
                foreach ($rows[$i] as $col) {
                    $html .= '<td>'.htmlspecialchars((string) $col, \ENT_QUOTES, 'UTF-8').'</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody>';
        }
        $html .= '</table>';

        return $html;
    }

    public function normalizeExt(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, \PATHINFO_EXTENSION));
        if ('' === $ext) {
            $lower = strtolower($filename);

            return match ($lower) {
                'csv', 'tsv', 'html', 'htm', 'md', 'markdown', 'gif' => '.'.$lower,
                default => '',
            };
        }

        return '.'.$ext;
    }

    private function fallbackRender(string $content, int $width): string
    {
        $wrapped = wordwrap($content, max(1, $width), "\n", true);

        return $wrapped;
    }
}

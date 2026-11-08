<?php

declare(strict_types=1);

namespace App\UI\Tui\View\Repo\Files;

use App\UI\Tui\Format\Ansi;
use App\UI\Tui\Format\Rows;
use App\UI\Tui\Render\CodeHighlighter;
use App\UI\Tui\View\Repo\RepoScreen;
use App\UI\Tui\View\Repo\RepoViews;
use App\UI\Tui\Widget\Clickable;
use App\UI\Tui\Widget\DocumentWidget;
use App\UI\Tui\Widget\LinesWidget;
use Symfony\Component\Process\Process;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\LoaderWidget;

final class FileView extends RepoScreen
{
    private readonly ContainerWidget $contentSlot;
    private readonly LinesWidget $crumb;
    private readonly LoaderWidget $loader;
    private readonly DocumentWidget $previewDoc;
    private readonly AbstractWidget $codeWidget;

    private bool $loaded = false;
    private bool $previewMode = false;
    /** @var list<string> */
    private array $rawLines = [];
    /** @var list<string>|null */
    private ?array $highlightedLines = null;
    private int $cursorLine = 0;
    private int $cursorCol = 0;

    public function __construct(
        RepoViews $repo,
        private readonly string $path,
        int $initialLine = 0,
        int $initialCol = 0,
        ?string $initialFlash = null,
    ) {
        parent::__construct($repo);
        $this->cursorLine = $initialLine;
        $this->cursorCol = $initialCol;

        if (null !== $initialFlash) {
            $this->repo->flash = $initialFlash;
        }

        $this->crumb = new LinesWidget(fn (int $cols): array => ["\e[2m".Ansi::fit('  '.$this->path, $cols)."\e[0m"]);

        $this->contentSlot = new ContainerWidget();
        $this->contentSlot->expandVertically(true);

        $this->loader = new LoaderWidget('Loading '.$this->path.'...');
        $this->loader->addStyleClass('indent');
        $this->contentSlot->add($this->loader);

        $this->previewDoc = new DocumentWidget($this->repo->views->renderer, $this->repo->async());
        $this->previewDoc->expandVertically(true);

        $this->codeWidget = new class($this) extends AbstractWidget implements Clickable {
            public function __construct(private readonly FileView $view)
            {
            }

            public function render(RenderContext $context): array
            {
                $cols = max(1, $context->getColumns());
                $rows = max(1, $context->getRows());

                $lines = $this->view->displayLines();

                return Rows::code($lines, $this->view->cursorLine(), $rows, $cols);
            }

            public function click(int $row, int $col, bool $nav): bool
            {
                return $this->view->handleClick($row, $col, $nav);
            }
        };

        $body = new ContainerWidget();
        $body->expandVertically(true);
        $body->add($this->crumb);
        $body->add($this->contentSlot);

        $this->add($repo->frame($this, $body, 1));
    }

    public function title(): string
    {
        return $this->path;
    }

    public function filePath(): string
    {
        return $this->path;
    }

    /**
     * @return list<string>
     */
    public function rawLines(): array
    {
        return $this->rawLines;
    }

    public function cursorLine(): int
    {
        return $this->cursorLine;
    }

    /**
     * @return list<string>
     */
    public function displayLines(): array
    {
        return $this->highlightedLines ?? $this->rawLines;
    }

    public function statusValue(): string
    {
        return $this->previewMode ? '[PREVIEW]' : '';
    }

    public function statusInfo(): string
    {
        return sprintf('Ln %d, Col %d', $this->cursorLine + 1, $this->cursorCol + 1);
    }

    public function shortcuts(): array
    {
        return [
            'back' => ['q', Key::ESCAPE],
            'toggle_preview' => ['p'],
            'up' => [Key::UP, 'k'],
            'down' => [Key::DOWN, 'j'],
            'left' => [Key::LEFT, 'h'],
            'right' => [Key::RIGHT, 'l'],
            'page_up' => [Key::PAGE_UP, 'ctrl+u', 'ctrl+b'],
            'page_down' => [Key::PAGE_DOWN, 'ctrl+d', 'ctrl+f', Key::SPACE],
            'top' => [Key::HOME, 'g'],
            'bottom' => [Key::END, 'shift+g'],
        ];
    }

    public function handleShortcut(string $action): bool
    {
        if (!$this->loaded) {
            return 'back' === $action ? $this->back() : false;
        }

        return match ($action) {
            'back' => $this->back(),
            'toggle_preview' => $this->togglePreview(),
            'up' => $this->moveCursor(-1, 0),
            'down' => $this->moveCursor(1, 0),
            'left' => $this->moveCursor(0, -1),
            'right' => $this->moveCursor(0, 1),
            'page_up' => $this->moveCursor(-10, 0),
            'page_down' => $this->moveCursor(10, 0),
            'top' => $this->setCursor(0, 0),
            'bottom' => $this->setCursor(\count($this->rawLines) - 1, 0),
            default => false,
        };
    }

    public function keyHints(): array
    {
        $hints = [
            [sprintf('Ln %d, Col %d', $this->cursorLine + 1, $this->cursorCol + 1), ''],
        ];

        if ($this->isMarkdown()) {
            $hints[] = [$this->previewMode ? 'raw' : 'preview', 'p'];
        }

        $hints[] = ['esc', 'back'];

        return $hints;
    }

    public function handleClick(int $row, int $col, bool $nav): bool
    {
        if (!$nav || [] === $this->rawLines) {
            return false;
        }

        $gutter = max(3, strlen((string) \count($this->rawLines)));
        $bodyRows = 20;
        $half = intdiv($bodyRows, 2);
        $top = max(0, min($this->cursorLine - $half, \count($this->rawLines) - $bodyRows));

        $targetLine = $top + $row;
        if (!isset($this->rawLines[$targetLine])) {
            return false;
        }

        $srcCol = max(0, $col - ($gutter + 2));
        $this->cursorLine = $targetLine;
        $this->cursorCol = $srcCol;
        $this->clampCol();
        $this->codeWidget->invalidate();

        $this->repo->root()->refreshChrome();

        return true;
    }

    protected function onActivate(): void
    {
        if (!$this->loaded) {
            $this->loadFile();
        }
    }

    private function isMarkdown(): bool
    {
        $ext = strtolower(pathinfo($this->path, \PATHINFO_EXTENSION));

        return in_array($ext, ['md', 'markdown', 'mdown', 'mkdn'], true);
    }

    private function back(): bool
    {
        $this->repo->navigator()->pop();

        return true;
    }

    private function loadFile(): void
    {
        $proc = $this->repo->views->reader->startBlobProcess($this->repo->state->git, $this->repo->state->ref, $this->path);
        $this->repo->async()->watch($this, $proc, function (Process $p): void {
            if (!$p->isSuccessful()) {
                $this->repo->flash = 'Cannot open '.$this->path;
                $this->repo->navigator()->pop();

                return;
            }

            $content = $p->getOutput();
            $this->rawLines = preg_split('/\r\n|\r|\n/', rtrim($content, "\n")) ?: [];
            $this->clampCol();
            $this->loaded = true;

            $this->contentSlot->clear();
            $this->contentSlot->add($this->codeWidget);

            $this->highlightContent($content);
            $this->repo->root()->refreshChrome();
        });
    }

    private function highlightContent(string $content): void
    {
        $highlighter = new CodeHighlighter();
        $lang = CodeHighlighter::languageFor($this->path);
        if (null === $lang) {
            return;
        }

        if (\count($this->rawLines) > 150) {
            $proc = $highlighter->startHighlightProcess($this->path, $content);
            $this->repo->async()->watch($this, $proc, function (Process $hp): void {
                if ($hp->isSuccessful()) {
                    $lines = preg_split('/\r\n|\r|\n/', rtrim($hp->getOutput(), "\n")) ?: [];
                    if (\count($lines) === \count($this->rawLines)) {
                        $this->highlightedLines = $lines;
                        $this->codeWidget->invalidate();
                        $this->repo->root()->refreshChrome();
                    }
                }
            });
        } else {
            $lines = $highlighter->highlightLines($this->path, $content);
            if (null !== $lines && \count($lines) === \count($this->rawLines)) {
                $this->highlightedLines = $lines;
                $this->codeWidget->invalidate();
            }
        }
    }

    private function moveCursor(int $dLine, int $dCol): bool
    {
        if ($this->previewMode) {
            return false;
        }

        $lastLine = max(0, \count($this->rawLines) - 1);
        $this->cursorLine = min(max(0, $this->cursorLine + $dLine), $lastLine);
        $this->cursorCol = max(0, $this->cursorCol + $dCol);
        $this->clampCol();
        $this->codeWidget->invalidate();
        $this->repo->root()->refreshChrome();

        return true;
    }

    private function setCursor(int $line, int $col): bool
    {
        if ($this->previewMode) {
            return false;
        }

        $lastLine = max(0, \count($this->rawLines) - 1);
        $this->cursorLine = min(max(0, $line), $lastLine);
        $this->cursorCol = max(0, $col);
        $this->clampCol();
        $this->codeWidget->invalidate();
        $this->repo->root()->refreshChrome();

        return true;
    }

    private function clampCol(): void
    {
        $len = isset($this->rawLines[$this->cursorLine]) ? mb_strlen($this->rawLines[$this->cursorLine]) : 0;
        $this->cursorCol = min($this->cursorCol, $len);
    }

    private function togglePreview(): bool
    {
        if (!$this->isMarkdown()) {
            return false;
        }

        $this->previewMode = !$this->previewMode;
        $this->contentSlot->clear();

        if ($this->previewMode) {
            $this->previewDoc->setDocument($this->path, implode("\n", $this->rawLines));
            $this->contentSlot->add($this->previewDoc);
        } else {
            $this->contentSlot->add($this->codeWidget);
        }

        $this->repo->root()->refreshChrome();

        return true;
    }
}

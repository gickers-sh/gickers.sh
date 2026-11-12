<?php

declare(strict_types=1);

namespace App\UI\Tui\View\Selection;

use App\UI\Tui\Format\Ansi;
use App\UI\Tui\Render\Theme;
use App\UI\Tui\View\View;
use App\UI\Tui\Views;
use App\UI\Tui\Widget\DocumentWidget;
use Symfony\Component\Process\Process;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

final class AboutPane extends View
{
    private readonly DocumentWidget $doc;
    private readonly AbstractWidget $indicator;
    private bool $loaded = false;

    public function __construct(private readonly Views $views)
    {
        $this->expandVertically(true);

        $this->doc = new DocumentWidget($this->views->renderer, $this->views->async());
        $this->doc->expandVertically(true);

        $this->indicator = new class($this->doc) extends AbstractWidget {
            public function __construct(private readonly DocumentWidget $doc) {}

            public function render(RenderContext $context): array
            {
                if ($this->doc->isLoading()) {
                    return [''];
                }

                $scrollWidget = $this->doc->scrollWidget();
                $lineCount = \count($scrollWidget->lines());
                if (0 === $lineCount) {
                    return [''];
                }

                $cols = max(1, $context->getColumns());
                $rows = max(1, $context->getRows());
                $scroll = $scrollWidget->scroll();
                $maxScroll = max(0, $lineCount - max(1, $rows - 1));
                $pct = $maxScroll > 0 ? (int) round(($scroll / $maxScroll) * 100) : 100;
                $pct = min(100, max(0, $pct));

                $text = Theme::style(\sprintf('☰ %d%%', $pct), Theme::MUTED);
                $width = Ansi::width($text);
                $pad = max(0, $cols - $width - 1);

                return [str_repeat(' ', $pad) . $text];
            }
        };

        $this->add($this->doc);
        $this->add($this->indicator);
    }

    public function title(): string
    {
        return 'About';
    }

    public function shortcuts(): array
    {
        return [
            'up' => [Key::UP, 'k'],
            'down' => [Key::DOWN, 'j'],
            'page_up' => [Key::PAGE_UP, 'ctrl+u', 'ctrl+b', 'b'],
            'page_down' => [Key::PAGE_DOWN, 'ctrl+d', 'ctrl+f', Key::SPACE, 'f'],
            'half_up' => ['u'],
            'half_down' => ['d'],
            'top' => [Key::HOME, 'g'],
            'bottom' => [Key::END, 'shift+g'],
            'reload' => ['r'],
        ];
    }

    public function handleShortcut(string $action): bool
    {
        if ($this->doc->isLoading()) {
            return false;
        }

        $scroll = $this->doc->scrollWidget();
        $rows = max(1, $scroll->rows());

        $handled = match ($action) {
            'up' => $scroll->setScroll($scroll->scroll() - 1),
            'down' => $scroll->setScroll($scroll->scroll() + 1),
            'page_up' => $scroll->setScroll($scroll->scroll() - max(1, $rows - 2)),
            'page_down' => $scroll->setScroll($scroll->scroll() + max(1, $rows - 2)),
            'half_up' => $scroll->setScroll($scroll->scroll() - (int) round($rows / 2)),
            'half_down' => $scroll->setScroll($scroll->scroll() + (int) round($rows / 2)),
            'top' => $scroll->setScroll(0),
            'bottom' => $scroll->setScroll(\count($scroll->lines())),
            'reload' => $this->refresh(),
            default => null,
        };

        if (null !== $handled) {
            $this->indicator->invalidate();

            return true;
        }

        return false;
    }

    public function keyHints(): array
    {
        return [
            ['↑↓', 'navigate'],
            ['tab', 'section'],
            ['r', 'refresh'],
            ['q', 'quit'],
            ['?', 'toggle help'],
        ];
    }

    public function refresh(): bool
    {
        $this->loaded = false;
        $this->loadAbout();

        return true;
    }

    public function beforeRender(): void
    {
        $this->indicator->invalidate();
    }

    protected function onActivate(): void
    {
        if (!$this->loaded) {
            $this->loadAbout();
        }
        $this->focus($this->doc->scrollWidget());
    }

    private function loadAbout(): void
    {
        $this->loaded = true;
        $this->doc->showLoading('Loading README...');

        try {
            $softRepo = $this->views->repoQuery->get($this->views->session()->actor, '.soft-serve');
            $gitPath = $this->views->repoDirectory->pathFor($softRepo->name());
            $git = $this->views->reader->openRepository($gitPath);

            $treeProcess = $this->views->reader->startTreeProcess($git, 'HEAD', '');
            $this->views->async()->watch($this, $treeProcess, function (Process $p) use ($git): void {
                if (!$p->isSuccessful()) {
                    $this->showNone();

                    return;
                }

                try {
                    $entries = $this->views->reader->parseTreeOutput($p->getOutput());
                    $readmeEntry = $this->views->reader->findReadmeEntry($entries);
                    if (null === $readmeEntry) {
                        $this->showNone();

                        return;
                    }

                    $blobProcess = $this->views->reader->startBlobProcess($git, 'HEAD', $readmeEntry->path);
                    $this->views->async()->watch($this, $blobProcess, function (Process $bp) use ($readmeEntry): void {
                        if (!$bp->isSuccessful()) {
                            $this->showNone();

                            return;
                        }
                        $this->doc->setDocument($readmeEntry->path, $bp->getOutput());
                    });
                } catch (\Throwable) {
                    $this->showNone();
                }
            });
        } catch (\Throwable) {
            $this->showNone();
        }
    }

    private function showNone(): void
    {
        $this->doc->setLines([
            '',
            Theme::style('No readme found.', Theme::MUTED),
            '',
            Theme::style('Create a `.soft-serve` repository and add a README.md file to display readme.', Theme::MUTED),
        ]);
    }
}

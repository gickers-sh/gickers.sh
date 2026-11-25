<?php

declare(strict_types=1);

namespace App\UI\Tui\View\Selection;

use App\UI\Tui\Format\Ansi;
use App\UI\Tui\Format\Chrome;
use App\UI\Tui\Format\Time;
use App\UI\Tui\Render\Theme;
use App\UI\Tui\View\View;
use App\UI\Tui\Views;
use App\UI\Tui\Widget\ChromeWidget;
use App\UI\Tui\Widget\DocumentWidget;
use App\UI\Tui\Widget\LinesWidget;
use App\Wiki\Domain\Model\WikiPage;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

final class WikiPageView extends View
{
    private ChromeWidget $chrome;
    private readonly LinesWidget $crumb;
    private readonly DocumentWidget $doc;
    private readonly AbstractWidget $indicator;
    private ?WikiPage $page = null;
    private ?string $flash = null;
    private bool $loaded = false;

    public function __construct(
        private readonly Views $views,
        private readonly string $slug,
    ) {
        $this->expandVertically(true);

        $this->crumb = new LinesWidget(function (int $cols): array {
            $page = $this->page;
            $cat = null !== $page ? $page->category() : 'Wiki';
            $title = null !== $page ? $page->title() : $this->slug;
            $updated = null !== $page ? ' · Updated '.Time::ago($page->updatedAt()) : '';

            $line = '  '.Theme::style('Wiki', Theme::MUTED)
                .Theme::style(' / ', Theme::BORDER2)
                .Theme::style($cat, Theme::CYAN)
                .Theme::style(' / ', Theme::BORDER2)
                .Theme::style($title, Theme::BLUE, bold: true)
                .Theme::style(' ('.$this->slug.')'.$updated, Theme::MUTED);

            return [Ansi::fit($line, $cols), Chrome::rule($cols)];
        });

        $this->doc = new DocumentWidget($this->views->renderer, $this->views->async());
        $this->doc->expandVertically(true);
        $this->doc->onLink(function (string $url): void {
            $this->flash = '🔗 '.$url;
            $this->chrome->refresh();
        });

        $this->indicator = new class($this->doc) extends AbstractWidget {
            public function __construct(private readonly DocumentWidget $doc)
            {
            }

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

                return [str_repeat(' ', $pad).$text];
            }
        };

        $body = new ContainerWidget();
        $body->expandVertically(true);
        $body->add($this->crumb);
        $body->add($this->doc);
        $body->add($this->indicator);

        $this->chrome = new ChromeWidget(null, $body);
        $this->chrome->setHeader(fn (int $cols): array => [
            ' '.Chrome::serverBadge('' !== $this->views->settings->name ? $this->views->settings->name : 'Soft Serve'),
            '',
        ]);
        $this->chrome->setStatus(function (int $cols): array {
            if (null !== $this->flash) {
                return ["\e[7m".Ansi::fit(' '.trim($this->flash), $cols)."\e[0m"];
            }

            return [Chrome::helpLine($this->keyHints(), $cols)];
        });

        $this->add($this->chrome);
    }

    public function title(): string
    {
        return null !== $this->page ? $this->page->title() : $this->slug;
    }

    public function findChrome(): ChromeWidget
    {
        return $this->chrome;
    }

    public function shortcuts(): array
    {
        return [
            'back' => ['q', Key::ESCAPE, Key::BACKSPACE, Key::LEFT, 'h'],
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
        if ('back' === $action) {
            $this->views->navigator()->pop();

            return true;
        }

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
            'reload' => $this->reload(),
            default => null,
        };

        if (null !== $handled) {
            $this->indicator->invalidate();

            return true;
        }

        return false;
    }

    public function onAnyKey(string $data): bool
    {
        if (null !== $this->flash) {
            $this->flash = null;
            $this->chrome->refresh();

            return true;
        }

        return false;
    }

    public function keyHints(): array
    {
        return [
            ['↑↓', 'scroll'],
            ['f/b', 'page down/up'],
            ['g/G', 'top/bottom'],
            ['r', 'refresh'],
            ['esc', 'back to wiki'],
            ['?', 'toggle help'],
        ];
    }

    public function helpColumns(): array
    {
        return [
            [['↑/k', 'scroll up'], ['↓/j', 'scroll down']],
            [['f/space', 'page down'], ['b', 'page up']],
            [['g/home', 'go to start'], ['G/end', 'go to end']],
            [['r', 'refresh'], ['esc/q', 'back to wiki'], ['?', 'toggle help']],
        ];
    }

    public function beforeRender(): void
    {
        $this->indicator->invalidate();
    }

    protected function onActivate(): void
    {
        if (!$this->loaded) {
            $this->loadPage();
        }
        $this->focus($this->doc->scrollWidget());
    }

    private function reload(): bool
    {
        $this->loadPage();
        $this->flash = 'Refreshed '.$this->slug;
        $this->chrome->refresh();

        return true;
    }

    private function loadPage(): void
    {
        $this->loaded = true;
        $query = $this->views->wikiQuery;
        if (null === $query) {
            $this->doc->setLines(['', '  '.Theme::style('Wiki unavailable.', Theme::MUTED)]);

            return;
        }

        $page = $query->find($this->slug);
        if (null === $page) {
            $this->doc->setLines(['', '  '.Theme::style('Wiki page not found.', Theme::MUTED)]);

            return;
        }

        $this->page = $page;
        $this->crumb->refresh();
        $this->doc->setDocument($page->slugString().'.md', $page->content());
        $this->chrome->refresh();
    }
}

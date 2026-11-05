<?php

declare(strict_types=1);

namespace App\UI\Tui\Page;

use App\UI\Tui\Render\Screen;
use App\UI\Tui\TuiHost;
use App\UI\Tui\Widget\BarWidget;
use App\UI\Tui\Widget\ContentWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Shared chrome for every page: a top margin, a header block, an optional tab
 * bar, a vertically-expanding {@see ContentWidget} body, and a footer (status
 * bar / help), stacked in a single vertical {@see ContainerWidget}. The chrome
 * rows are width-aware {@see BarWidget}s so subclasses can right-align and
 * stretch content to the real terminal width. All input is handled by the
 * subclass.
 */
abstract class AbstractPage implements Page
{
    protected TuiHost $host;
    protected BarWidget $headerWidget;
    protected BarWidget $tabBarWidget;
    protected BarWidget $footerWidget;
    protected ContentWidget $contentWidget;
    protected ContainerWidget $root;

    public function mount(TuiHost $host): void
    {
        $this->host = $host;

        $marginTop = new BarWidget(static fn (int $cols): array => ['']);
        $this->headerWidget = new BarWidget(fn (int $cols): array => $this->headerLines($cols));
        $this->tabBarWidget = new BarWidget(fn (int $cols): array => $this->tabBarLines($cols));
        $this->footerWidget = new BarWidget(fn (int $cols): array => $this->footerLines($cols));
        $this->contentWidget = new ContentWidget();
        $this->contentWidget->expandVertically(true);
        $this->contentWidget->setPainter(fn (int $cols, int $rows): array => $this->paintBody($cols, $rows));

        $this->root = new ContainerWidget();
        $this->root->expandVertically(true);
        $this->root->add($marginTop);
        $this->root->add($this->headerWidget);
        $this->root->add($this->tabBarWidget);
        $this->root->add($this->contentWidget);
        $this->root->add($this->footerWidget);

        $host->tui()->add($this->root);

        $this->load();
    }

    public function sync(): void
    {
        $this->headerWidget->markDirty();
        $this->tabBarWidget->markDirty();
        $this->footerWidget->markDirty();
        $this->contentWidget->markDirty();
    }

    public function tick(?float $deltaTime = null): bool
    {
        return false;
    }

    /**
     * Load data after the widget tree is mounted. Overridable.
     */
    protected function load(): void
    {
    }

    protected function headerText(): string
    {
        return '';
    }

    protected function tabBarText(): string
    {
        return '';
    }

    protected function footerText(): string
    {
        return '';
    }

    /**
     * Header rows for the given inner width. Defaults to the single-line
     * {@see headerText()}; rich pages override.
     *
     * @return list<string>
     */
    protected function headerLines(int $cols): array
    {
        $t = $this->headerText();

        return '' === trim($t) ? [] : [$t];
    }

    /**
     * @return list<string>
     */
    protected function tabBarLines(int $cols): array
    {
        $t = $this->tabBarText();

        return '' === trim($t) ? [] : [$t];
    }

    /**
     * @return list<string>
     */
    protected function footerLines(int $cols): array
    {
        $t = $this->footerText();

        return '' === trim($t) ? [] : [$t];
    }

    /**
     * @return list<string>
     */
    abstract protected function paintBody(int $cols, int $rows): array;

    /**
     * A body consisting of a single informational line near the top.
     *
     * @return list<string>
     */
    protected function messageBody(string $message, int $cols): array
    {
        return [Screen::blank($cols), Screen::fit('  '.$message, $cols)];
    }
}

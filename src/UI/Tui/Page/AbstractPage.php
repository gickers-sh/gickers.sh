<?php

declare(strict_types=1);

namespace App\UI\Tui\Page;

use App\UI\Tui\Render\Screen;
use App\UI\Tui\TuiHost;
use App\UI\Tui\Widget\ContentWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Shared chrome for every page: a header line, an optional tab bar, a
 * vertically-expanding {@see ContentWidget} body, and a footer line, stacked in
 * a single vertical {@see ContainerWidget}. Subclasses provide the four text
 * sources and paint the body. All input is handled by the subclass.
 */
abstract class AbstractPage implements Page
{
    protected TuiHost $host;
    protected TextWidget $headerWidget;
    protected TextWidget $tabBarWidget;
    protected TextWidget $footerWidget;
    protected ContentWidget $contentWidget;
    protected ContainerWidget $root;

    public function mount(TuiHost $host): void
    {
        $this->host = $host;

        $this->headerWidget = new TextWidget('', true);
        $this->tabBarWidget = new TextWidget('', true);
        $this->footerWidget = new TextWidget('', true);
        $this->contentWidget = new ContentWidget();
        $this->contentWidget->expandVertically(true);
        $this->contentWidget->setPainter(fn (int $cols, int $rows): array => $this->paintBody($cols, $rows));

        $this->root = new ContainerWidget();
        $this->root->expandVertically(true);
        $this->root->add($this->headerWidget);
        $this->root->add($this->tabBarWidget);
        $this->root->add($this->contentWidget);
        $this->root->add($this->footerWidget);

        $host->tui()->add($this->root);

        $this->load();
    }

    public function sync(): void
    {
        $this->headerWidget->setText($this->headerText());
        $this->tabBarWidget->setText($this->tabBarText());
        $this->footerWidget->setText($this->footerText());
        $this->contentWidget->markDirty();
    }

    /**
     * Load data after the widget tree is mounted. Overridable.
     */
    protected function load(): void
    {
    }

    abstract protected function headerText(): string;

    abstract protected function tabBarText(): string;

    abstract protected function footerText(): string;

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

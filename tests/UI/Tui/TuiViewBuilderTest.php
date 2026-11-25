<?php

declare(strict_types=1);

namespace App\Tests\UI\Tui;

use App\UI\Tui\Builder\TuiViewBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;

final class TuiViewBuilderTest extends TestCase
{
    public function testBuildsWidgetTreeWithInteractiveElements(): void
    {
        $html = <<<'HTML'
<div>
    <header class="header">
        <h1>codes.sh</h1>
    </header>
    <tui:container direction="horizontal">
        <tui:input id="search-box" class="indent"></tui:input>
        <tui:select-list id="repo-list" max-visible="10">
            <item value="soft-serve" label="soft-serve" description="Git server"></item>
            <item value="termstrap" label="termstrap" description="Terminal CSS engine"></item>
        </tui:select-list>
    </tui:container>
</div>
HTML;

        $builder = new TuiViewBuilder();
        $tree = $builder->build($html, 80);

        $this->assertNotNull($tree);

        $search = $builder->getWidget('search-box');
        $this->assertInstanceOf(InputWidget::class, $search);

        $list = $builder->getWidget('repo-list');
        $this->assertInstanceOf(SelectListWidget::class, $list);
    }
}

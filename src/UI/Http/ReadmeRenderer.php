<?php

declare(strict_types=1);

namespace App\UI\Http;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use Tempest\Highlight\CommonMark\HighlightExtension;
use Tempest\Highlight\Highlighter;
use Tempest\Highlight\Themes\CssTheme;
use Twig\Markup;

final readonly class ReadmeRenderer
{
    private MarkdownConverter $converter;

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $environment->addExtension(new HighlightExtension(new Highlighter(new CssTheme())));

        $this->converter = new MarkdownConverter($environment);
    }

    public function render(string $markdown): Markup
    {
        return new Markup($this->converter->convert($markdown)->getContent(), 'UTF-8');
    }
}

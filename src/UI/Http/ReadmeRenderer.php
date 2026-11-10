<?php

declare(strict_types=1);

namespace App\UI\Http;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use Twig\Markup;

final readonly class ReadmeRenderer
{
    private GithubFlavoredMarkdownConverter $converter;

    public function __construct()
    {
        $this->converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    public function render(string $markdown): Markup
    {
        return new Markup($this->converter->convert($markdown)->getContent(), 'UTF-8');
    }
}

<?php

declare(strict_types=1);

namespace App\UI\Tui\Builder;

use App\UI\Tui\Render\TwigTuiRenderer;
use App\UI\Tui\Widget\LinesWidget;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;

/**
 * Builds an interactive symfony/tui AbstractWidget tree from HTML/Twig templates,
 * rendering visual sections via Termstrap and mounting native interactive widgets.
 */
final class TuiViewBuilder
{
    /** @var array<string, AbstractWidget> */
    private array $widgetsById = [];

    public function __construct(
        private readonly ?TwigTuiRenderer $renderer = null,
    ) {
    }

    /**
     * Build an AbstractWidget tree from an HTML string.
     */
    public function build(string $html, int $width = 80): ContainerWidget
    {
        $this->widgetsById = [];
        $root = new ContainerWidget();
        $root->expandVertically(true);

        // Standardize self-closing custom tags for HTML5 parser
        $html = preg_replace('/<(tui:input|input)([^>]*?)\/>/i', '<$1$2></$1>', $html) ?? $html;

        try {
            $doc = HTMLDocument::createFromString($html, LIBXML_NOERROR);
            $body = $doc->getElementsByTagName('body')->item(0);
            if ($body === null) {
                return $root;
            }

            foreach ($body->childNodes as $child) {
                $widget = $this->buildNode($child, $width);
                if ($widget !== null) {
                    $root->add($widget);
                }
            }
        } catch (\Throwable) {
            // Fallback: entire HTML as a pre-rendered block
            if ($this->renderer !== null) {
                $root->add(new LinesWidget(explode("\n", $this->renderer->renderHtml($html, $width))));
            }
        }

        return $root;
    }

    /**
     * Retrieve an interactive widget mounted with an id="...".
     */
    public function getWidget(string $id): ?AbstractWidget
    {
        return $this->widgetsById[$id] ?? null;
    }

    private function buildNode(Node $node, int $width): ?AbstractWidget
    {
        if (!$node instanceof Element) {
            return null;
        }

        $tag = strtolower($node->tagName);
        $id = $node->getAttribute('id') ?? '';

        // Interactive: <tui:input> or <input>
        if ($tag === 'tui:input' || $tag === 'input') {
            $widget = new InputWidget();
            if ($node->hasAttribute('class')) {
                foreach (explode(' ', (string) $node->getAttribute('class')) as $cls) {
                    if ($cls !== '') {
                        $widget->addStyleClass($cls);
                    }
                }
            }
            if ($id !== '') {
                $this->widgetsById[$id] = $widget;
            }
            return $widget;
        }

        // Interactive: <tui:select-list> or <select>
        if ($tag === 'tui:select-list' || $tag === 'select') {
            $items = [];
            foreach ($node->getElementsByTagName('item') as $itemEl) {
                $items[] = [
                    'value' => (string) $itemEl->getAttribute('value'),
                    'label' => (string) ($itemEl->getAttribute('label') ?? $itemEl->textContent),
                    'description' => (string) ($itemEl->getAttribute('description') ?? ''),
                ];
            }
            $maxVisible = $node->hasAttribute('max-visible') ? (int) $node->getAttribute('max-visible') : 5;
            $widget = new SelectListWidget($items, maxVisible: $maxVisible);
            if ($node->hasAttribute('class')) {
                foreach (explode(' ', (string) $node->getAttribute('class')) as $cls) {
                    if ($cls !== '') {
                        $widget->addStyleClass($cls);
                    }
                }
            }
            if ($id !== '') {
                $this->widgetsById[$id] = $widget;
            }
            return $widget;
        }

        // Structural Container: <tui:container>, <tui:hsplit>
        if ($tag === 'tui:container' || $tag === 'tui:hsplit' || $node->hasAttribute('data-tui-container')) {
            $container = new ContainerWidget();
            if ($tag === 'tui:hsplit' || strtolower((string) $node->getAttribute('direction')) === 'horizontal') {
                $container->setStyle((new \Symfony\Component\Tui\Style\Style())->withDirection(Direction::Horizontal));
            }
            if ($node->hasAttribute('class')) {
                foreach (explode(' ', (string) $node->getAttribute('class')) as $cls) {
                    if ($cls !== '') {
                        $container->addStyleClass($cls);
                    }
                }
            }
            foreach ($node->childNodes as $child) {
                $childWidget = $this->buildNode($child, $width);
                if ($childWidget !== null) {
                    $container->add($childWidget);
                }
            }
            if ($id !== '') {
                $this->widgetsById[$id] = $container;
            }
            return $container;
        }

        // Check if children contain any interactive widgets
        if ($this->hasInteractiveDescendants($node)) {
            $container = new ContainerWidget();
            foreach ($node->childNodes as $child) {
                $childWidget = $this->buildNode($child, $width);
                if ($childWidget !== null) {
                    $container->add($childWidget);
                }
            }
            return $container;
        }

        // Pure visual subtree: Render via Termstrap
        $doc = $node->ownerDocument;
        $nodeHtml = $doc instanceof HTMLDocument ? $doc->saveHTML($node) : '';
        $renderedAnsi = $this->renderer !== null
            ? $this->renderer->renderHtml($nodeHtml, $width)
            : \Termstrap\Termstrap::toAnsi($nodeHtml, ['width' => $width]);

        $lines = preg_split('/\r\n|\r|\n/', rtrim($renderedAnsi, "\n")) ?: [];
        return new LinesWidget($lines);
    }

    private function hasInteractiveDescendants(Element $element): bool
    {
        $interactiveTags = ['input', 'select', 'tui:input', 'tui:select-list', 'tui:container', 'tui:hsplit'];
        foreach ($interactiveTags as $it) {
            if ($element->getElementsByTagName($it)->length > 0) {
                return true;
            }
        }
        return false;
    }
}

<?php

declare(strict_types=1);

namespace App\UI\Tui\Debug;

use App\UI\Tui\Widget\Clickable;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ParentInterface;
use Symfony\Component\Tui\Widget\WidgetContainerInterface;

/**
 * Inspects the live TUI widget tree, extracting component hierarchies,
 * geometric coordinates (WidgetRect), internal props/state, and reflection locations.
 */
final class ComponentInspector
{
    /**
     * Builds a serializable hierarchy tree of widgets starting from $root.
     *
     * @return array<string, mixed>
     */
    public function buildTree(AbstractWidget $root, Renderer $renderer): array
    {
        return $this->formatNode($root, $renderer, 0);
    }

    /**
     * Formats detailed inspection information for a specific widget.
     *
     * @return array<string, mixed>
     */
    public function inspectWidget(AbstractWidget $widget, Renderer $renderer): array
    {
        $ref = new \ReflectionClass($widget);
        $rect = $renderer->getWidgetRect($widget);
        $hidden = true === $renderer->resolveStyle($widget)->getHidden();

        $interfaces = array_values(class_implements($widget) ?: []);
        $shortInterfaces = array_map(fn (string $i) => basename(str_replace('\\', '/', $i)), $interfaces);

        $props = $this->extractProps($widget, $ref);

        // 1. Ancestor Hierarchy Trace (Component Stacktrace)
        $hierarchy = [];
        $curr = $widget;
        while ($curr) {
            $currRef = new \ReflectionClass($curr);
            $hierarchy[] = [
                'short_name' => $currRef->getShortName(),
                'fqcn' => $currRef->getName(),
                'file' => $currRef->getFileName() ?: '',
                'line' => $currRef->getStartLine() ?: 0,
            ];
            $curr = $curr->getParent();
        }
        $hierarchy = array_reverse($hierarchy);

        // 2. Computed / Resolved TUI Style
        $resolvedStyle = $renderer->resolveStyle($widget);
        $color = $resolvedStyle->getColor();
        $bg = $resolvedStyle->getBackground();
        $pad = $resolvedStyle->getPadding();
        $border = $resolvedStyle->getBorder();
        $dir = $resolvedStyle->getDirection();

        $computedStyle = [
            'color' => null !== $color ? $color->toHex() : null,
            'background' => null !== $bg ? $bg->toHex() : null,
            'bold' => $resolvedStyle->getBold(),
            'dim' => $resolvedStyle->getDim(),
            'italic' => $resolvedStyle->getItalic(),
            'underline' => $resolvedStyle->getUnderline(),
            'direction' => null !== $dir ? $dir->name : null,
            'padding' => null !== $pad ? [
                'top' => $pad->top,
                'right' => $pad->right,
                'bottom' => $pad->bottom,
                'left' => $pad->left,
            ] : null,
            'border' => null !== $border ? ($border->pattern->isNone() ? 'none' : 'present') : null,
        ];

        // 3. Matched CSS Rules from theme.css and Termstrap Bootstrap
        $cssData = $this->resolveMatchedCss($widget, $hierarchy);

        return [
            'id' => spl_object_hash($widget),
            'short_name' => $ref->getShortName(),
            'fqcn' => $ref->getName(),
            'file' => $ref->getFileName() ?: '',
            'line' => $ref->getStartLine() ?: 0,
            'rect' => $rect ? [
                'row' => $rect->row,
                'col' => $rect->col,
                'columns' => $rect->columns,
                'rows' => $rect->rows,
            ] : null,
            'hidden' => $hidden,
            'clickable' => $widget instanceof Clickable,
            'interfaces' => $shortInterfaces,
            'props' => $props,
            'hierarchy' => $hierarchy,
            'computed_style' => $computedStyle,
            'css' => $cssData,
        ];
    }

    /**
     * Resolves matching CSS rules from assets/tui/theme.css and Termstrap Bootstrap.
     *
     * @param list<array<string, mixed>> $hierarchy
     * @return array<string, mixed>
     */
    private function resolveMatchedCss(AbstractWidget $widget, array $hierarchy): array
    {
        $shortName = (new \ReflectionClass($widget))->getShortName();
        $ancestorNames = [];
        foreach ($hierarchy as $h) {
            if (isset($h['short_name']) && is_string($h['short_name'])) {
                $ancestorNames[] = $h['short_name'];
            }
        }

        $tokens = $widget->getStyleClasses();
        $tokens[] = strtolower($shortName);

        $templates = [];
        $bootstrapRules = [];

        $isListCardContext = in_array('RepoListPane', $ancestorNames, true)
            || in_array('SelectionView', $ancestorNames, true)
            || in_array('ListWidget', $ancestorNames, true)
            || in_array('FilterableListWidget', $ancestorNames, true);

        if ($isListCardContext) {
            $tokens = array_merge($tokens, ['list-card', 'card-header', 'card-title', 'card-meta', 'card-desc', 'card-action', 'card-hash']);
            $templates[] = '@tui/pages/feed/repo_item.html.twig';
            $templates[] = '@tui/components/list_card.html.twig';

            $bootstrapRules[] = [
                'selector' => '.card-header',
                'source' => 'Termstrap Base Framework (bootstrap.css:4439)',
                'css' => "padding: var(--bs-card-cap-padding-y) var(--bs-card-cap-padding-x);\nmargin-bottom: 0;\ncolor: var(--bs-card-cap-color);\nbackground-color: var(--bs-card-cap-bg);\nborder-bottom: var(--bs-card-border-width) solid var(--bs-card-border-color); /* <-- Séparateur par défaut */",
            ];
            $bootstrapRules[] = [
                'selector' => '.card-title',
                'source' => 'Termstrap Base Framework (bootstrap.css)',
                'css' => "margin-bottom: var(--bs-card-title-spacer-y);\ncolor: var(--bs-card-title-color);",
            ];
            $bootstrapRules[] = [
                'selector' => '.card',
                'source' => 'Termstrap Base Framework (bootstrap.css:4390)',
                'css' => "position: relative;\ndisplay: flex;\nflex-direction: column;\nmin-width: 0;",
            ];
        }

        if (in_array('TabBarWidget', $ancestorNames, true) || 'TabBarWidget' === $shortName) {
            $tokens = array_merge($tokens, ['tabbar', 'tab', 'tab-active']);
        }
        if (in_array('ChromeWidget', $ancestorNames, true) || 'ChromeWidget' === $shortName) {
            $tokens = array_merge($tokens, ['chrome', 'header', 'status', 'help', 'status-bar']);
        }
        if (in_array('DocumentWidget', $ancestorNames, true) || 'DocumentWidget' === $shortName) {
            $tokens = array_merge($tokens, ['h1', 'h2', 'h3', 'pre', 'rule', 'code']);
        }

        $tokens = array_values(array_unique(array_filter($tokens)));

        // Match against assets/tui/theme.css
        $themeRules = [];
        $cssPath = \dirname(__DIR__, 4) . '/assets/tui/theme.css';
        if (file_exists($cssPath)) {
            $cssContent = (string) file_get_contents($cssPath);
            if (preg_match_all('/([^{]+)\{([^}]+)\}/s', $cssContent, $matches, \PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $sel = trim($m[1]);
                    $rules = trim($m[2]);

                    $matched = false;
                    foreach ($tokens as $token) {
                        if (str_contains($sel, $token) || str_contains($sel, '.' . $token)) {
                            $matched = true;
                            break;
                        }
                    }

                    if ($matched) {
                        $themeRules[] = [
                            'selector' => $sel,
                            'source' => 'assets/tui/theme.css',
                            'css' => $rules,
                        ];
                    }
                }
            }
        }

        return [
            'tokens' => $tokens,
            'templates' => $templates,
            'theme_css' => $themeRules,
            'bootstrap_css' => $bootstrapRules,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatNode(AbstractWidget $widget, Renderer $renderer, int $depth): array
    {
        $ref = new \ReflectionClass($widget);
        $rect = $renderer->getWidgetRect($widget);
        $hidden = true === $renderer->resolveStyle($widget)->getHidden();

        $children = [];
        if ($widget instanceof WidgetContainerInterface || $widget instanceof ParentInterface) {
            foreach ($widget->all() as $child) {
                $children[] = $this->formatNode($child, $renderer, $depth + 1);
            }
        }

        return [
            'id' => spl_object_hash($widget),
            'short_name' => $ref->getShortName(),
            'fqcn' => $ref->getName(),
            'file' => $ref->getFileName() ?: '',
            'line' => $ref->getStartLine() ?: 0,
            'depth' => $depth,
            'rect' => $rect ? [
                'row' => $rect->row,
                'col' => $rect->col,
                'columns' => $rect->columns,
                'rows' => $rect->rows,
            ] : null,
            'hidden' => $hidden,
            'clickable' => $widget instanceof Clickable,
            'children_count' => count($children),
            'children' => $children,
        ];
    }

    /**
     * Safely extracts props without crashing on uninitialized properties or circular refs.
     *
     * @param \ReflectionClass<AbstractWidget>|\ReflectionClass<object> $ref
     * @return array<string, mixed>
     */
    private function extractProps(AbstractWidget $widget, \ReflectionClass $ref): array
    {
        $props = [];
        $current = $ref;
        while ($current) {
            foreach ($current->getProperties() as $prop) {
                $name = $prop->getName();
                if (isset($props[$name])) {
                    continue;
                }

                $prop->setAccessible(true);
                if (!$prop->isInitialized($widget)) {
                    $props[$name] = '<uninitialized>';
                    continue;
                }

                // Skip internal engine references that would explode the JSON dump
                if (in_array($name, ['tui', 'navigator', 'async', 'renderer', 'session', 'apps', 'keybindings', 'renderCache'], true)) {
                    continue;
                }

                try {
                    $val = $prop->getValue($widget);
                    $props[$name] = $this->formatValue($val);
                } catch (\Throwable $e) {
                    $props[$name] = '<error: ' . $e->getMessage() . '>';
                }
            }
            $current = $current->getParentClass();
        }

        return $props;
    }

    private function formatValue(mixed $val, int $depth = 0): mixed
    {
        if ($depth > 2) {
            return is_object($val) ? get_class($val) : (is_array($val) ? '[...]' : $val);
        }

        if (null === $val || is_scalar($val)) {
            return $val;
        }

        if (is_array($val)) {
            $formatted = [];
            $count = 0;
            foreach ($val as $k => $v) {
                if ($count++ > 20) {
                    $formatted['...'] = '(' . (count($val) - 20) . ' more items)';
                    break;
                }
                $formatted[$k] = $this->formatValue($v, $depth + 1);
            }

            return $formatted;
        }

        if ($val instanceof \Closure) {
            return 'Closure()';
        }

        if ($val instanceof \DateTimeInterface) {
            return $val->format(\DateTimeInterface::ATOM);
        }

        if (is_object($val)) {
            if (method_exists($val, '__toString')) {
                return (string) $val;
            }

            return get_class($val);
        }

        return get_debug_type($val);
    }
}

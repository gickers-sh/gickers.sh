<?php

declare(strict_types=1);

namespace App\UI\Ssh\Help;

use App\Access\Domain\AccessLevel;
use App\Repo\Domain\RepoName;
use App\Wiki\Application\DefaultWikiPages;
use App\Wiki\Application\Query\WikiQuery;

/**
 * Central documentation registry that transforms the unified CommandCatalog tree
 * into formatted structures for both the Web UI (interactive palettes, drawer forms,
 * command reference pages) and external documentation.
 */
final readonly class CommandDocumentationRegistry
{
    public function __construct(
        private CommandCatalog $catalog,
        private ?WikiQuery $wikiQuery = null,
    ) {}

    /**
     * Build the repository-scoped CLI palette for web repository views.
     *
     * @return list<array{
     *     category: string,
     *     tier: string,
     *     title: string,
     *     description: string,
     *     base: string,
     *     remote: string,
     *     command: string,
     *     docsUrl: ?string,
     *     wiki: ?array{slug: string, title: string, category: string, url: string, cliCommand: string, tuiPath: string},
     *     fields: list<array{kind: string, key: string, label: string, placeholder: string, required: bool, default: string, flag: string, choices: list<string>}>
     * }>
     */
    public function repoPalette(RepoName $name, AccessLevel $level, string $sshPrefix): array
    {
        $canWrite = $level->canWrite();
        $isAdmin = $level->isAdmin();
        $repoName = $name->value;

        $root = $this->catalog->root($isAdmin);
        $entries = $this->collectUiEntries($root, 'repo');
        usort($entries, static fn(array $a, array $b): int => $a['spec']->order <=> $b['spec']->order);

        $commands = [];
        foreach ($entries as $entry) {
            $node = $entry['node'];
            $spec = $entry['spec'];
            if (('write' === $spec->tier && !$canWrite) || ('admin' === $spec->tier && !$isAdmin)) {
                continue;
            }

            $base = str_replace('{repo}', $repoName, $spec->baseTemplate);
            $fields = array_map(static fn(FieldSpec $f): array => $f->toArray(), $spec->fields);
            $remote = $this->renderRemote($base, $fields);
            $cmdPath = trim($node->commandPath());
            $docsUrl = '' !== $cmdPath ? '/docs/' . str_replace(' ', '/', $cmdPath) : null;

            $commands[] = [
                'category' => $spec->category,
                'tier' => $spec->tier,
                'title' => $spec->title,
                'description' => $spec->description,
                'base' => $base,
                'fields' => $fields,
                'remote' => $remote,
                'command' => trim($sshPrefix . ' ' . $remote),
                'docsUrl' => $docsUrl,
                'wiki' => $this->resolveWikiInfo($node, $sshPrefix),
            ];
        }

        return $commands;
    }

    /**
     * Collect full documentation for all commands (used by /docs web page).
     *
     * @return list<array{
     *     name: string,
     *     path: string,
     *     slug: string,
     *     url: string,
     *     parentPath: ?string,
     *     parentUrl: ?string,
     *     short: string,
     *     long: string,
     *     doc: array{lead: string, scope: ?string, arguments: list<array{name: string, description: string, optional: bool}>, output: ?string, notes: ?string},
     *     example: string,
     *     synopsis: string,
     *     use: string,
     *     formalUse: string,
     *     flags: list<array{name: string, shorthand: ?string, varname: string, usage: string, default: ?string}>,
     *     ui: list<array{category: string, tier: string, title: string, description: string, base: string, fields: list<array{kind: string, key: string, label: string, placeholder: string, required: bool, default: string, flag: string, choices: list<string>}>}>,
     *     category: string,
     *     tier: string,
     *     scope: string,
     *     wiki: ?array{slug: string, title: string, category: string, url: string, cliCommand: string, tuiPath: string},
     *     isGroup: bool,
     *     subcommands: list<array{name: string, path: string, slug: string, url: string, short: string, tier: string}>,
     *     breadcrumbs: list<array{label: string, url: ?string}>,
     * }>
     */
    public function allCommands(bool $isAdmin, string $sshPrefix): array
    {
        $root = $this->catalog->root($isAdmin);
        $nodes = [];
        $this->flattenCommands($root, $nodes);

        $result = [];
        foreach ($nodes as $node) {
            if ($node->hidden || ('' === $node->name && '' === $node->commandPath())) {
                continue;
            }

            $result[] = $this->formatCommand($node, $sshPrefix);
        }

        return $result;
    }

    /**
     * Find a command by path tokens (e.g. "repo/branch/protect" or "repo branch protect").
     * Returns null if not found or unauthorized for the given admin level.
     *
     * @return array{
     *     name: string,
     *     path: string,
     *     slug: string,
     *     url: string,
     *     parentPath: ?string,
     *     parentUrl: ?string,
     *     short: string,
     *     long: string,
     *     doc: array{lead: string, scope: ?string, arguments: list<array{name: string, description: string, optional: bool}>, output: ?string, notes: ?string},
     *     example: string,
     *     synopsis: string,
     *     use: string,
     *     formalUse: string,
     *     flags: list<array{name: string, shorthand: ?string, varname: string, usage: string, default: ?string}>,
     *     ui: list<array{category: string, tier: string, title: string, description: string, base: string, fields: list<array{kind: string, key: string, label: string, placeholder: string, required: bool, default: string, flag: string, choices: list<string>}>}>,
     *     category: string,
     *     tier: string,
     *     scope: string,
     *     wiki: ?array{slug: string, title: string, category: string, url: string, cliCommand: string, tuiPath: string},
     *     isGroup: bool,
     *     subcommands: list<array{name: string, path: string, slug: string, url: string, short: string, tier: string}>,
     *     breadcrumbs: list<array{label: string, url: ?string}>,
     * }|null
     */
    public function findCommand(string $pathOrSlug, bool $isAdmin, string $sshPrefix): ?array
    {
        $clean = trim($pathOrSlug, " \t\n\r\0\x0B/");
        if ('' === $clean) {
            return null;
        }

        $split = preg_split('#[/ ]+#', $clean);
        $tokens = array_values(array_filter(is_array($split) ? $split : []));
        if ([] === $tokens) {
            return null;
        }

        $root = $this->catalog->root($isAdmin);
        $node = $root->find($tokens);
        if (null !== $node && !$node->hidden && '' !== trim($node->commandPath())) {
            return $this->formatCommand($node, $sshPrefix);
        }

        // Fallback matching by slug or dashed representation
        foreach ($this->allCommands($isAdmin, $sshPrefix) as $cmd) {
            if ($cmd['slug'] === $clean || $cmd['path'] === $clean || str_replace(' ', '-', $cmd['path']) === $clean) {
                $matchedTokens = array_values(array_filter(explode(' ', $cmd['path'])));
                $matchedNode = $root->find($matchedTokens);
                if (null !== $matchedNode) {
                    return $this->formatCommand($matchedNode, $sshPrefix);
                }
            }
        }

        return null;
    }

    /**
     * @return array{
     *     name: string,
     *     path: string,
     *     slug: string,
     *     url: string,
     *     parentPath: ?string,
     *     parentUrl: ?string,
     *     short: string,
     *     long: string,
     *     doc: array{lead: string, scope: ?string, arguments: list<array{name: string, description: string, optional: bool}>, output: ?string, notes: ?string},
     *     example: string,
     *     synopsis: string,
     *     use: string,
     *     formalUse: string,
     *     flags: list<array{name: string, shorthand: ?string, varname: string, usage: string, default: ?string}>,
     *     ui: list<array{category: string, tier: string, title: string, description: string, base: string, fields: list<array{kind: string, key: string, label: string, placeholder: string, required: bool, default: string, flag: string, choices: list<string>}>}>,
     *     category: string,
     *     tier: string,
     *     scope: string,
     *     wiki: ?array{slug: string, title: string, category: string, url: string, cliCommand: string, tuiPath: string},
     *     isGroup: bool,
     *     subcommands: list<array{name: string, path: string, slug: string, url: string, short: string, tier: string}>,
     *     breadcrumbs: list<array{label: string, url: ?string}>,
     * }
     */
    public function formatCommand(CommandSpec $node, string $sshPrefix): array
    {
        $flags = array_map(static fn(FlagSpec $f): array => [
            'name' => $f->name,
            'shorthand' => $f->shorthand,
            'varname' => $f->varname,
            'usage' => $f->usage,
            'default' => $f->defaultText,
        ], $node->flags);

        $uiList = array_map(fn(CommandUiSpec $u): array => [
            'category' => $u->category,
            'tier' => $u->tier,
            'title' => $u->title,
            'description' => $u->description,
            'base' => str_replace('{repo}', '<repo>', $u->baseTemplate),
            'fields' => array_map(static fn(FieldSpec $f): array => $f->toArray(), $u->fields),
        ], $node->uiSpecs);

        $primaryUi = $node->uiSpecs[0] ?? null;
        $category = null !== $primaryUi ? $primaryUi->category : $this->inferCategory($node);
        $tier = null !== $primaryUi ? $primaryUi->tier : 'read';
        $scope = null !== $primaryUi ? $primaryUi->scope : 'global';

        $cmdPath = trim($node->commandPath());
        $slug = str_replace(' ', '/', $cmdPath);
        $example = HelpRenderer::interpolateSsh($node->example, $sshPrefix);
        $synopsis = trim($sshPrefix . ' ' . $cmdPath);

        $parentPath = ($node->parent && '' !== trim($node->parent->commandPath())) ? trim($node->parent->commandPath()) : null;
        $parentUrl = null !== $parentPath ? '/docs/' . str_replace(' ', '/', $parentPath) : '/docs';

        $use = $node->use;
        $formalUse = trim($sshPrefix . ' ' . ($parentPath ? $parentPath . ' ' : '') . $use);

        $tokens = array_values(array_filter(explode(' ', $cmdPath)));
        $breadcrumbs = [];
        $accum = [];
        foreach ($tokens as $idx => $token) {
            $accum[] = $token;
            $isLast = $idx === count($tokens) - 1;
            $breadcrumbs[] = [
                'label' => $token,
                'url' => $isLast ? null : '/docs/' . implode('/', $accum),
            ];
        }

        $subcommands = [];
        foreach ($node->visibleChildren() as $child) {
            $childPath = trim($child->commandPath());
            $childUi = $child->uiSpecs[0] ?? null;
            $subcommands[] = [
                'name' => $child->name,
                'path' => $childPath,
                'slug' => str_replace(' ', '/', $childPath),
                'url' => '/docs/' . str_replace(' ', '/', $childPath),
                'short' => $child->short,
                'tier' => null !== $childUi ? $childUi->tier : 'read',
            ];
        }

        $parsedDoc = $this->parseLongDescription($node->long);

        return [
            'name' => $node->name,
            'path' => $cmdPath,
            'slug' => $slug,
            'url' => '/docs/' . $slug,
            'parentPath' => $parentPath,
            'parentUrl' => $parentUrl,
            'short' => $node->short,
            'long' => $node->long,
            'doc' => $parsedDoc,
            'example' => $example,
            'synopsis' => $synopsis,
            'use' => $use,
            'formalUse' => $formalUse,
            'flags' => $flags,
            'ui' => $uiList,
            'category' => $category,
            'tier' => $tier,
            'scope' => $scope,
            'wiki' => $this->resolveWikiInfo($node, $sshPrefix),
            'isGroup' => $node->hasChildren() && !$node->runnable,
            'subcommands' => $subcommands,
            'breadcrumbs' => $breadcrumbs,
        ];
    }

    /**
     * @return array{slug: string, title: string, category: string, url: string, cliCommand: string, tuiPath: string}|null
     */
    private function resolveWikiInfo(CommandSpec $node, string $sshPrefix): ?array
    {
        $wikiSlug = $node->resolveWikiSlug();
        if ('' === $wikiSlug) {
            return null;
        }

        $title = null;
        $category = null;
        if (null !== $this->wikiQuery) {
            try {
                $page = $this->wikiQuery->find($wikiSlug);
                if (null !== $page) {
                    $title = $page->title();
                    $category = $page->category();
                }
            } catch (\Throwable) {
                // Fall back to static DefaultWikiPages metadata when DB is unavailable
            }
        }

        if (null === $title) {
            $meta = DefaultWikiPages::metadata()[$wikiSlug] ?? null;
            $title = $meta['title'] ?? ucwords(str_replace('-', ' ', $wikiSlug));
            $category = $meta['category'] ?? 'Wiki';
        }

        return [
            'slug' => $wikiSlug,
            'title' => $title,
            'category' => $category ?? 'Wiki',
            'url' => '/wiki/' . $wikiSlug,
            'cliCommand' => trim($sshPrefix . ' wiki show ' . $wikiSlug),
            'tuiPath' => 'Wiki → ' . $wikiSlug,
        ];
    }

    /**
     * Parse structured sections from command long description.
     *
     * @return array{
     *     lead: string,
     *     scope: ?string,
     *     arguments: list<array{name: string, description: string, optional: bool}>,
     *     output: ?string,
     *     notes: ?string,
     * }
     */
    public function parseLongDescription(string $raw): array
    {
        $lines = explode("\n", trim($raw));
        /** @var array<string, list<string>> $grouped */
        $grouped = [];
        $current = 'lead';

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (1 === preg_match('/^(Scope|Arguments|Output|Notes|Flags):\s*$/', $trimmed, $m)) {
                $current = $m[1];
                continue;
            }
            $grouped[$current][] = $line;
        }

        $leadText = isset($grouped['lead']) ? trim(implode("\n", $grouped['lead'])) : '';
        $scopeText = isset($grouped['Scope']) ? trim(implode("\n", $grouped['Scope'])) : null;
        $outputText = isset($grouped['Output']) ? trim(implode("\n", $grouped['Output'])) : null;
        $notesText = isset($grouped['Notes']) ? trim(implode("\n", $grouped['Notes'])) : null;

        $arguments = isset($grouped['Arguments']) ? $this->parseArguments($grouped['Arguments']) : [];

        return [
            'lead' => $leadText,
            'scope' => '' !== $scopeText ? $scopeText : null,
            'arguments' => $arguments,
            'output' => '' !== $outputText ? $outputText : null,
            'notes' => '' !== $notesText ? $notesText : null,
        ];
    }

    /**
     * @param list<string> $lines
     *
     * @return list<array{name: string, description: string, optional: bool}>
     */
    private function parseArguments(array $lines): array
    {
        $arguments = [];
        foreach ($lines as $line) {
            if (1 === preg_match('/^\s*([A-Za-z0-9_\[\]\.\-]+)\s{2,}(.+)$/', $line, $m)) {
                $name = trim($m[1]);
                $desc = trim($m[2]);
                $optional = str_starts_with($name, '[') || str_contains(strtolower($desc), 'optional');
                $arguments[] = [
                    'name' => $name,
                    'description' => $desc,
                    'optional' => $optional,
                ];
            } elseif ([] !== $arguments && 1 === preg_match('/^\s{4,}(.+)$/', $line, $m)) {
                $lastIndex = count($arguments) - 1;
                $arguments[$lastIndex] = [
                    'name' => $arguments[$lastIndex]['name'],
                    'description' => $arguments[$lastIndex]['description'] . ' ' . trim($m[1]),
                    'optional' => $arguments[$lastIndex]['optional'],
                ];
            }
        }

        return $arguments;
    }

    /**
     * Render the default remote command (no prefix) from a base and its fields,
     * mirroring the client-side builder in repo-web.js.
     *
     * @param list<array{kind: string, key: string, label: string, placeholder: string, required: bool, default: string, flag: string, choices: list<string>}> $fields
     */
    public function renderRemote(string $base, array $fields): string
    {
        $out = $base;
        foreach ($fields as $f) {
            $value = $f['default'];
            switch ($f['kind']) {
                case 'bool':
                    if ('' !== $value) {
                        $out .= ' ' . $f['flag'];
                    }
                    break;
                case 'flag':
                case 'choice-flag':
                    if ('' !== $value) {
                        $out .= ' ' . $f['flag'] . ' ' . $this->shellQuote($value);
                    }
                    break;
                default: // arg, choice-arg
                    $out .= ' ' . ('' !== $value ? $this->shellQuote($value) : '<' . $f['key'] . '>');
            }
        }

        return $out;
    }

    public function shellQuote(string $value): string
    {
        if (1 === preg_match('/^[A-Za-z0-9_@%+=:,.\/-]+$/', $value)) {
            return $value;
        }

        return "'" . str_replace("'", "'\\''", $value) . "'";
    }

    /**
     * @return list<array{node: CommandSpec, spec: CommandUiSpec}>
     */
    private function collectUiEntries(CommandSpec $node, string $scopeFilter): array
    {
        $entries = [];
        foreach ($node->uiSpecs as $spec) {
            if ('repo' === $scopeFilter && ('repo' === $spec->scope || 'both' === $spec->scope)) {
                $entries[] = ['node' => $node, 'spec' => $spec];
            } elseif ('global' === $scopeFilter && ('global' === $spec->scope || 'both' === $spec->scope)) {
                $entries[] = ['node' => $node, 'spec' => $spec];
            }
        }

        foreach ($node->children as $child) {
            foreach ($this->collectUiEntries($child, $scopeFilter) as $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param list<CommandSpec> $nodes
     */
    private function flattenCommands(CommandSpec $node, array &$nodes): void
    {
        $nodes[] = $node;
        foreach ($node->children as $child) {
            $this->flattenCommands($child, $nodes);
        }
    }

    private function inferCategory(CommandSpec $node): string
    {
        $path = $node->commandPath();
        if (str_starts_with($path, ' repo')) {
            return 'repo';
        }
        if (str_starts_with($path, ' user')) {
            return 'admin';
        }
        if (str_starts_with($path, ' settings')) {
            return 'settings';
        }
        if (str_starts_with($path, ' pubkey')) {
            return 'keys';
        }
        if (str_starts_with($path, ' token')) {
            return 'tokens';
        }
        if (str_starts_with($path, ' wiki')) {
            return 'wiki';
        }

        return 'explore';
    }
}

<?php

declare(strict_types=1);

namespace App\UI\Ssh\Help;

use App\Access\Domain\AccessLevel;
use App\Repo\Domain\RepoName;

/**
 * Central documentation registry that transforms the unified CommandCatalog tree
 * into formatted structures for both the Web UI (interactive palettes, drawer forms,
 * command reference pages) and external documentation.
 */
final readonly class CommandDocumentationRegistry
{
    public function __construct(
        private CommandCatalog $catalog,
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
     *     fields: list<array{kind: string, key: string, label: string, placeholder: string, required: bool, default: string, flag: string, choices: list<string>}>
     * }>
     */
    public function repoPalette(RepoName $name, AccessLevel $level, string $sshPrefix): array
    {
        $canWrite = $level->canWrite();
        $isAdmin = $level->isAdmin();
        $repoName = $name->value;

        $root = $this->catalog->root($isAdmin);
        $uiSpecs = $this->collectUiSpecs($root, 'repo');
        usort($uiSpecs, static fn(CommandUiSpec $a, CommandUiSpec $b): int => $a->order <=> $b->order);

        $commands = [];
        foreach ($uiSpecs as $spec) {
            if (('write' === $spec->tier && !$canWrite) || ('admin' === $spec->tier && !$isAdmin)) {
                continue;
            }

            $base = str_replace('{repo}', $repoName, $spec->baseTemplate);
            $fields = array_map(static fn(FieldSpec $f): array => $f->toArray(), $spec->fields);
            $remote = $this->renderRemote($base, $fields);

            $commands[] = [
                'category' => $spec->category,
                'tier' => $spec->tier,
                'title' => $spec->title,
                'description' => $spec->description,
                'base' => $base,
                'fields' => $fields,
                'remote' => $remote,
                'command' => trim($sshPrefix . ' ' . $remote),
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
     *     short: string,
     *     long: string,
     *     example: string,
     *     synopsis: string,
     *     flags: list<array{name: string, shorthand: ?string, varname: string, usage: string, default: ?string}>,
     *     ui: list<array{category: string, tier: string, title: string, description: string, base: string, fields: list<array{kind: string, key: string, label: string, placeholder: string, required: bool, default: string, flag: string, choices: list<string>}>}>,
     *     category: string,
     *     tier: string,
     *     scope: string,
     *     isGroup: bool,
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
            $category = $primaryUi?->category ?? $this->inferCategory($node);
            $tier = $primaryUi?->tier ?? 'read';
            $scope = $primaryUi?->scope ?? 'global';

            $cmdPath = trim($node->commandPath());
            $example = str_replace('{ssh}', $sshPrefix, $node->example);
            $synopsis = trim($sshPrefix . ' ' . $cmdPath);

            $result[] = [
                'name' => $node->name,
                'path' => $cmdPath,
                'short' => $node->short,
                'long' => $node->long,
                'example' => $example,
                'synopsis' => $synopsis,
                'flags' => $flags,
                'ui' => $uiList,
                'category' => $category,
                'tier' => $tier,
                'scope' => $scope,
                'isGroup' => $node->hasChildren() && !$node->runnable,
            ];
        }

        return $result;
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
     * @return list<CommandUiSpec>
     */
    private function collectUiSpecs(CommandSpec $node, string $scopeFilter): array
    {
        $specs = [];
        foreach ($node->uiSpecs as $spec) {
            if ('repo' === $scopeFilter && ('repo' === $spec->scope || 'both' === $spec->scope)) {
                $specs[] = $spec;
            } elseif ('global' === $scopeFilter && ('global' === $spec->scope || 'both' === $spec->scope)) {
                $specs[] = $spec;
            }
        }

        foreach ($node->children as $child) {
            foreach ($this->collectUiSpecs($child, $scopeFilter) as $s) {
                $specs[] = $s;
            }
        }

        return $specs;
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

        return 'explore';
    }
}

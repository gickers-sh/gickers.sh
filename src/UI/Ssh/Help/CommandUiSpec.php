<?php

declare(strict_types=1);

namespace App\UI\Ssh\Help;

/**
 * Presentation metadata for rendering interactive UI cards, palettes, and web forms
 * derived from a command specification.
 */
final readonly class CommandUiSpec
{
    /**
     * @param string          $category     category for grouping/filtering ('explore', 'files', 'git', 'write', 'admin', 'identity', 'keys', 'tokens', 'settings')
     * @param string          $tier         access tier: 'read', 'write', or 'admin'
     * @param string          $title        human-readable title
     * @param string          $description  short explanation of the action
     * @param string          $baseTemplate command template with optional tokens like '{repo}'
     * @param list<FieldSpec> $fields       editable parameters / options
     * @param string          $scope        'repo' (requires a repository context), 'global', or 'both'
     * @param int             $order        display ordering weight
     */
    public function __construct(
        public string $category,
        public string $tier,
        public string $title,
        public string $description,
        public string $baseTemplate,
        public array $fields = [],
        public string $scope = 'repo',
        public int $order = 0,
    ) {}
}

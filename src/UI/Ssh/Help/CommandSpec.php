<?php

declare(strict_types=1);

namespace App\UI\Ssh\Help;

/**
 * A node in the SSH command tree, mirroring a cobra.Command for help rendering.
 *
 * This structure is used only to render `help`/`--help` output byte-for-byte
 * identical to the upstream Soft Serve (cobra) server; command execution is
 * handled separately by the Symfony console commands.
 */
final class CommandSpec
{
    public ?CommandSpec $parent = null;

    /** @var list<CommandSpec> */
    public array $children = [];

    /**
     * @param string         $name     command name (first word of Use)
     * @param string         $use      full cobra Use string, e.g. "create REPOSITORY"
     * @param list<string>   $aliases  aliases excluding the name itself
     * @param list<FlagSpec> $flags    declared flags, excluding the implicit --help
     * @param bool           $runnable whether the command has a run action (leaf) vs. being a pure group
     */
    public function __construct(
        public string $name,
        public string $use,
        public string $short = '',
        public string $long = '',
        public array $aliases = [],
        public array $flags = [],
        public bool $runnable = false,
        public bool $hidden = false,
    ) {
    }

    public function addChild(self $child): self
    {
        $child->parent = $this;
        $this->children[] = $child;

        return $this;
    }

    public function hasChildren(): bool
    {
        return [] !== $this->visibleChildren();
    }

    /**
     * @return list<CommandSpec>
     */
    public function visibleChildren(): array
    {
        return array_values(array_filter($this->children, static fn (self $c): bool => !$c->hidden));
    }

    /**
     * Resolve a descendant by following token names/aliases. Returns null when
     * no path matches.
     *
     * @param list<string> $tokens
     */
    public function find(array $tokens): ?self
    {
        $node = $this;
        foreach ($tokens as $token) {
            $next = null;
            foreach ($node->children as $child) {
                if ($child->name === $token || in_array($token, $child->aliases, true)) {
                    $next = $child;
                    break;
                }
            }
            if (null === $next) {
                return null;
            }
            $node = $next;
        }

        return $node;
    }

    /**
     * cobra CommandPath: ancestor names joined by spaces. The root carries an
     * empty name, so top-level commands keep a leading space.
     */
    public function commandPath(): string
    {
        $names = [];
        for ($c = $this; null !== $c; $c = $c->parent) {
            array_unshift($names, $c->name);
        }

        return implode(' ', $names);
    }

    /**
     * cobra NamePadding: max(11, longest child name length).
     */
    public function namePadding(): int
    {
        $max = 0;
        foreach ($this->children as $child) {
            $len = strlen($child->name);
            if ($len > $max) {
                $max = $len;
            }
        }

        return max(11, $max);
    }
}

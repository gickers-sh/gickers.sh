<?php

declare(strict_types=1);

namespace App\UI\Ssh;

final readonly class ResolvedCommand
{
    /**
     * @param list<string> $arguments
     */
    public function __construct(
        public string $name,
        public array $arguments,
    ) {
    }
}

final class SshCommandResolver
{
    /** @var array<string, bool> */
    private array $canonical = [];

    /** @var array<string, string> */
    private array $aliasToCanonical = [];

    /**
     * @param list<string>                $registeredCommandNames
     * @param array<string, list<string>> $aliasMap
     */
    public function __construct(
        array $registeredCommandNames = [],
        array $aliasMap = [],
    ) {
        foreach ($registeredCommandNames as $name) {
            $this->canonical[$name] = true;
        }

        foreach ($aliasMap as $canonicalName => $aliases) {
            foreach ($aliases as $alias) {
                $this->aliasToCanonical[$alias] = $canonicalName;
            }
        }
    }

    /**
     * @param list<string> $tokens
     */
    public function resolve(array $tokens): ?ResolvedCommand
    {
        if ([] === $tokens) {
            return null;
        }

        $tokens = $this->normalizeTokens($tokens);
        $count = count($tokens);
        $maxPrefix = min($count, 4);

        for ($k = $maxPrefix; $k >= 1; --$k) {
            $candidate = implode(':', array_slice($tokens, 0, $k));
            /** @var list<string> $remaining */
            $remaining = array_slice($tokens, $k);

            if (isset($this->canonical[$candidate])) {
                return new ResolvedCommand($candidate, $remaining);
            }

            if (isset($this->aliasToCanonical[$candidate])) {
                return new ResolvedCommand($this->aliasToCanonical[$candidate], $remaining);
            }

            if (1 === $k && in_array($candidate, ['help', 'list'], true)) {
                return new ResolvedCommand($candidate, $remaining);
            }
        }

        return null;
    }

    /**
     * @param list<string> $tokens
     *
     * @return list<string>
     */
    private function normalizeTokens(array $tokens): array
    {
        if ([] === $tokens) {
            return [];
        }

        // Top-level promotions
        if (in_array($tokens[0], ['collab', 'collabs', 'collaborator', 'collaborators'], true)) {
            array_shift($tokens);
            array_unshift($tokens, 'repo', 'collab');
        } elseif (in_array($tokens[0], ['webhook', 'webhooks'], true)) {
            array_shift($tokens);
            array_unshift($tokens, 'repo', 'webhook');
        } elseif (count($tokens) >= 2 && 'repo' === $tokens[0] && in_array($tokens[1], ['pr', 'prs', 'pull-request', 'pull-requests'], true)) {
            array_shift($tokens); // remove 'repo'
            $tokens[0] = 'pr';
        }

        // Group alias normalizations on token 0
        $tokens[0] = match ($tokens[0]) {
            'repos', 'repository', 'repositories' => 'repo',
            'prs', 'pull-request', 'pull-requests' => 'pr',
            'pubkeys', 'publickey', 'publickeys' => 'pubkey',
            'access-token' => 'token',
            'users' => 'user',
            default => $tokens[0],
        };

        // Group alias normalizations on token 1 when token 0 is 'repo'
        if (count($tokens) >= 2 && 'repo' === $tokens[0]) {
            $tokens[1] = match ($tokens[1]) {
                'collabs', 'collaborator', 'collaborators' => 'collab',
                'webhooks' => 'webhook',
                'branches' => 'branch',
                'tags' => 'tag',
                default => $tokens[1],
            };
        }

        // Shortcuts:
        // repo branch <repo> -> repo branch list <repo>
        if (count($tokens) >= 2 && 'repo' === $tokens[0] && 'branch' === $tokens[1]) {
            $sub = $tokens[2] ?? null;
            if (null !== $sub && !in_array($sub, ['list', 'ls', 'default', 'delete', 'del', 'rm', 'remove'], true) && !str_starts_with($sub, '-')) {
                array_splice($tokens, 2, 0, ['list']);
            }
        }

        // repo tag <repo> -> repo tag list <repo>
        if (count($tokens) >= 2 && 'repo' === $tokens[0] && 'tag' === $tokens[1]) {
            $sub = $tokens[2] ?? null;
            if (null !== $sub && !in_array($sub, ['list', 'ls', 'delete', 'del', 'rm', 'remove'], true) && !str_starts_with($sub, '-')) {
                array_splice($tokens, 2, 0, ['list']);
            }
        }

        return $tokens;
    }
}

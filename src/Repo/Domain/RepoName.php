<?php

declare(strict_types=1);

namespace App\Repo\Domain;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * Canonical repository name (Go `utils.SanitizeRepo` + `utils.ValidateRepo`).
 */
final readonly class RepoName implements \Stringable
{
    private function __construct(public string $value)
    {
    }

    /**
     * Sanitizes user input (`/foo/bar.git/` → `foo/bar`) then validates it.
     */
    public static function fromInput(string $raw): self
    {
        $name = self::sanitize($raw);
        if ('' === $name) {
            throw new InvalidInput('repo cannot be empty');
        }
        if (!preg_match('#^[\\p{L}\\p{N}._/-]+$#u', $name)) {
            throw new InvalidInput('repo can only contain letters, numbers, hyphens, underscores, periods, and slashes');
        }
        foreach (explode('/', $name) as $segment) {
            if (str_ends_with(strtolower($segment), '.git')) {
                throw new InvalidInput('repo path segments cannot end in .git');
            }
        }

        return new self($name);
    }

    public static function sanitize(string $raw): string
    {
        $raw = preg_replace('/\\x1b\\[[0-9;?]*[ -\\/]*[@-~]/', '', $raw) ?? $raw;
        $raw = trim($raw, " \t\n\r\0\x0B'\"");
        $segments = [];
        foreach (explode('/', $raw) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }
            if ('..' === $segment) {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        while ([] !== $segments) {
            $last = array_pop($segments);
            while (str_ends_with($last, '.git')) {
                $last = substr($last, 0, -4);
            }
            if ('' === $last || '.' === $last) {
                continue;
            }
            if ('..' === $last) {
                array_pop($segments);
                continue;
            }
            $segments[] = $last;
            break;
        }

        return implode('/', $segments);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

<?php

declare(strict_types=1);

namespace App\Wiki\Domain;

use App\Shared\Domain\Exception\InvalidInput;

final readonly class WikiSlug
{
    public function __construct(
        public string $value,
    ) {
        if ('' === $this->value || strlen($this->value) > 128) {
            throw new InvalidInput('invalid wiki slug');
        }
        if (1 !== preg_match('/^[a-z0-9][a-z0-9._-]*(\/[a-z0-9][a-z0-9._-]*)*$/', $this->value)) {
            throw new InvalidInput('invalid wiki slug: must contain only lowercase alphanumeric characters, hyphens, underscores, dots, or path separators');
        }
    }

    public static function fromInput(string $raw): self
    {
        $normalized = strtolower(trim($raw, " \t\n\r\0\x0B/"));
        if (str_ends_with($normalized, '.md')) {
            $normalized = substr($normalized, 0, -3);
        }

        return new self($normalized);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

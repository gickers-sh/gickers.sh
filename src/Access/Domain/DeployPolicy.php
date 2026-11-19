<?php

declare(strict_types=1);

namespace App\Access\Domain;

use App\Shared\Domain\Exception\InvalidInput;

enum DeployPolicy: string
{
    case AdminsOnly = 'admins-only';
    case Authorized = 'authorized';
    case RepoAdmins = 'repo-admins';

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        return self::tryFrom($normalized) ?? throw new InvalidInput(sprintf('invalid deploy policy "%s" (expected: admins-only, authorized, repo-admins)', $value));
    }

    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom(strtolower(trim($value)));
    }
}

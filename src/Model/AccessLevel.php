<?php

declare(strict_types=1);

namespace App\Model;

/**
 * AccessLevel represents the permission level of a user for a repository.
 * Matches pkg/access/access.go in Soft Serve.
 */
enum AccessLevel: int
{
    case NoAccess = 0;
    case ReadOnlyAccess = 1;
    case ReadWriteAccess = 2;
    case AdminAccess = 3;

    public function canRead(): bool
    {
        return $this->value >= self::ReadOnlyAccess->value;
    }

    public function canWrite(): bool
    {
        return $this->value >= self::ReadWriteAccess->value;
    }

    public function isAdmin(): bool
    {
        return $this === self::AdminAccess;
    }

    public function toString(): string
    {
        return match ($this) {
            self::NoAccess => 'no-access',
            self::ReadOnlyAccess => 'read-only',
            self::ReadWriteAccess => 'read-write',
            self::AdminAccess => 'admin-access',
        };
    }

    public static function fromString(string $s): self
    {
        $normalized = strtolower(trim($s));

        return match ($normalized) {
            'no-access', 'none', 'no_access', '0' => self::NoAccess,
            'read-only', 'readonly', 'read_only', 'read', 'ro', '1' => self::ReadOnlyAccess,
            'read-write', 'readwrite', 'read_write', 'write', 'rw', '2' => self::ReadWriteAccess,
            'admin-access', 'adminaccess', 'admin_access', 'admin', '3' => self::AdminAccess,
            default => throw new \InvalidArgumentException(sprintf('Invalid access level: "%s"', $s)),
        };
    }

    public static function tryFromString(?string $s): ?self
    {
        if ($s === null || $s === '') {
            return null;
        }

        try {
            return self::fromString($s);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}

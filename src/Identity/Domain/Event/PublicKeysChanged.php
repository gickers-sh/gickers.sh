<?php

declare(strict_types=1);

namespace App\Identity\Domain\Event;

/**
 * Any public key was added or removed (or a user deleted); authorized_keys must be regenerated.
 */
final readonly class PublicKeysChanged
{
    public function __construct(public int $userId)
    {
    }
}

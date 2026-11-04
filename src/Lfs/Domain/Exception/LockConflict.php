<?php

declare(strict_types=1);

namespace App\Lfs\Domain\Exception;

use App\Lfs\Domain\Model\LfsLock;
use App\Shared\Domain\Exception\DomainException;

/**
 * Thrown when trying to lock a path that is already locked on a repository.
 */
final class LockConflict extends DomainException
{
    public function __construct(
        public readonly LfsLock $existingLock,
        string $message = 'lock already exists',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}

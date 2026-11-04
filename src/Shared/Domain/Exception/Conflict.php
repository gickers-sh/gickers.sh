<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * The operation conflicts with the current state (duplicate, invalid transition).
 */
final class Conflict extends DomainException
{
}

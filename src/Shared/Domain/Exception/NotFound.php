<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * A requested resource does not exist, or is hidden from the caller (denyRead).
 */
final class NotFound extends DomainException
{
}

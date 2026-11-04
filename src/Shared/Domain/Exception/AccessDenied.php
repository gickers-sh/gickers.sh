<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * The caller is not allowed to perform the operation.
 */
final class AccessDenied extends DomainException
{
}

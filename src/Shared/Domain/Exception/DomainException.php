<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * Base class of errors raised by domain rules; UI layers map subclasses to SSH/HTTP responses.
 */
abstract class DomainException extends \RuntimeException
{
}

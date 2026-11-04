<?php

declare(strict_types=1);

namespace App\UI\Http\Security;

use App\Access\Domain\AccessLevel;
use App\Repo\Domain\Model\Repo;

/**
 * Result of {@see GitAccessAuthorizer::decide()}: the verdict plus the readable
 * repository (null when the caller may not read it or it does not exist) and the
 * resolved access level.
 */
final readonly class GitAuthorization
{
    public function __construct(
        public GitVerdict $verdict,
        public ?Repo $repo,
        public AccessLevel $level,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\CodeIntel\Application\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Request to build and store the SCIP code-intelligence index for one commit of a repository.
 */
#[AsMessage('async')]
final readonly class IndexCommit
{
    public function __construct(
        public string $repo,
        public string $sha,
    ) {
    }
}

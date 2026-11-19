<?php

declare(strict_types=1);

namespace App\Deploy\Application\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage('async')]
final readonly class RunDeploy
{
    public function __construct(
        public int $releaseId,
    ) {
    }
}

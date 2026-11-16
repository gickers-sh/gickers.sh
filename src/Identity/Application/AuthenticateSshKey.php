<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;

final readonly class AuthenticateSshKey
{
    public function __construct(
        private UserStore $userStore,
    ) {
    }

    public function __invoke(string $fingerprint, ?string $targetUsername = null): ?User
    {
        if (null !== $targetUsername && '' !== trim($targetUsername)) {
            return $this->userStore->byFingerprintAndUsername($fingerprint, trim($targetUsername));
        }

        return $this->userStore->byFingerprint($fingerprint);
    }
}

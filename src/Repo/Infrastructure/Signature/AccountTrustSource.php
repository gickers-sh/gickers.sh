<?php

declare(strict_types=1);

namespace App\Repo\Infrastructure\Signature;

use App\Identity\Domain\UserStore;
use App\Repo\Domain\Signature\TrustSource;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Trust source backed by account-linked keys: every SSH public key registered to a
 * user account is trusted for commit signatures. Accounts carry no GPG keys yet, so
 * {@see gpgTrustedKeys()} is empty until a GPG key store exists.
 */
#[AsAlias(TrustSource::class)]
final readonly class AccountTrustSource implements TrustSource
{
    public function __construct(private UserStore $users)
    {
    }

    public function sshTrustedKeys(): array
    {
        $keys = [];
        foreach ($this->users->all() as $user) {
            foreach ($user->publicKeys() as $publicKey) {
                $keys[] = $publicKey->authorizedKey();
            }
        }

        return $keys;
    }

    public function gpgTrustedKeys(): array
    {
        return [];
    }
}

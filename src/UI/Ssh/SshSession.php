<?php

declare(strict_types=1);

namespace App\UI\Ssh;

use App\Identity\Domain\Model\User;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Identity of the current SSH session (one `soft:ssh:dispatch` process per connection).
 *
 * Set once by the SSH entrypoint after key authentication; read by SSH commands.
 */
final class SshSession implements ResetInterface
{
    private ?User $user = null;
    private ?string $publicKeyFingerprint = null;

    public function authenticate(?User $user, ?string $publicKeyFingerprint): void
    {
        $this->user = $user;
        $this->publicKeyFingerprint = $publicKeyFingerprint;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function publicKeyFingerprint(): ?string
    {
        return $this->publicKeyFingerprint;
    }

    public function reset(): void
    {
        $this->user = null;
        $this->publicKeyFingerprint = null;
    }
}

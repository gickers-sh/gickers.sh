<?php

declare(strict_types=1);

namespace App\UI\Ssh;

use App\Identity\Domain\Model\AccessToken;
use App\Identity\Domain\Model\User;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Identity of the current SSH session (one `soft:ssh:dispatch` process per connection).
 *
 * Set once by the SSH entrypoint after key or token authentication; read by SSH commands.
 */
final class SshSession implements ResetInterface
{
    private ?User $user = null;
    private ?string $publicKeyFingerprint = null;
    private ?string $authorizedKey = null;
    private ?AccessToken $accessToken = null;

    public function authenticate(
        ?User $user,
        ?string $publicKeyFingerprint,
        ?string $authorizedKey = null,
        ?AccessToken $accessToken = null,
    ): void {
        $this->user = $user;
        $this->publicKeyFingerprint = $publicKeyFingerprint;
        $this->authorizedKey = $authorizedKey;
        $this->accessToken = $accessToken;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function publicKeyFingerprint(): ?string
    {
        return $this->publicKeyFingerprint;
    }

    /** The raw `type base64 [comment]` key the session connected with, when known (needed for signup). */
    public function authorizedKey(): ?string
    {
        return $this->authorizedKey;
    }

    public function accessToken(): ?AccessToken
    {
        return $this->accessToken;
    }

    public function reset(): void
    {
        $this->user = null;
        $this->publicKeyFingerprint = null;
        $this->authorizedKey = null;
        $this->accessToken = null;
    }
}

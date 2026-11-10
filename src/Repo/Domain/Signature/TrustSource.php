<?php

declare(strict_types=1);

namespace App\Repo\Domain\Signature;

/**
 * Supplies the public keys trusted for commit-signature verification. The default
 * implementation draws from account-linked keys; a server-wide trust store can be
 * composed in later without changing {@see CommitSignatureVerifier}.
 */
interface TrustSource
{
    /**
     * Trusted SSH public keys in `authorized_keys` form (`<algo> <base64> [comment]`).
     *
     * @return list<string>
     */
    public function sshTrustedKeys(): array;

    /**
     * Trusted OpenPGP public keys, ASCII-armored.
     *
     * @return list<string>
     */
    public function gpgTrustedKeys(): array;
}

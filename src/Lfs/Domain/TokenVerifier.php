<?php

declare(strict_types=1);

namespace App\Lfs\Domain;

use App\Shared\Domain\Exception\AccessDenied;

/**
 * Validates the LFS bearer tokens minted by {@see TokenIssuer} and returns the
 * token subject (Go `pkg/web/auth.go:parseJWT`).
 */
interface TokenVerifier
{
    /**
     * @return non-empty-string the validated token subject
     *
     * @throws AccessDenied when the token is malformed or any claim is invalid
     */
    public function verify(string $token, string $expectedIssuer, string $expectedAudience): string;
}

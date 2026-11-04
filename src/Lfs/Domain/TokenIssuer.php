<?php

declare(strict_types=1);

namespace App\Lfs\Domain;

/**
 * Mints signed access tokens granting Git LFS HTTP access to one or more
 * repositories (Go `pkg/ssh/cmd/jwt.go`).
 */
interface TokenIssuer
{
    /**
     * @param list<string> $audience the repositories the token is valid for
     */
    public function issue(
        string $subject,
        array $audience,
        string $issuer,
        \DateTimeImmutable $issuedAt,
        \DateTimeImmutable $expiresAt,
    ): string;
}

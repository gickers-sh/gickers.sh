<?php

declare(strict_types=1);

namespace App\Deploy\Domain;

interface DomainDnsVerifier
{
    /**
     * Verifies step 3 of the domain pipeline: checks that the domain resolves in public DNS
     * and points to the codes.sh server (or is a sub-domain of the server's public host).
     */
    public function verifyPublicDns(string $domain): void;
}

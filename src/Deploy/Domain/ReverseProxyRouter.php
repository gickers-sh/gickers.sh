<?php

declare(strict_types=1);

namespace App\Deploy\Domain;

use App\Deploy\Domain\Model\DeployDomain;

interface ReverseProxyRouter
{
    /**
     * Synchronizes the reverse proxy configuration for all active domains and optionally reloads Caddy.
     *
     * @param list<DeployDomain> $activeDomains
     */
    public function syncDomains(array $activeDomains, bool $reload = true): void;

    /**
     * Performs an HTTP smoke test against a deployed domain or upstream service
     * and returns the HTTP status code (or null on connection/gateway failure).
     */
    public function smokeTest(DeployDomain $domain): ?int;
}

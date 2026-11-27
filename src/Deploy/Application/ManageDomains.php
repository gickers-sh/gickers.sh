<?php

declare(strict_types=1);

namespace App\Deploy\Application;

use App\Deploy\Domain\DeployStatus;
use App\Deploy\Domain\DeployStore;
use App\Deploy\Domain\DomainDnsVerifier;
use App\Deploy\Domain\Model\DeployDomain;
use App\Deploy\Domain\ReverseProxyRouter;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Domain\Exception\NotFound;
use App\Shared\Infrastructure\Config\ServerSettings;
use Psr\Clock\ClockInterface;

final readonly class ManageDomains
{
    public function __construct(
        private DeployAccessResolver $accessResolver,
        private DeployStore $deployStore,
        private DomainDnsVerifier $dnsVerifier,
        private ReverseProxyRouter $proxyRouter,
        private TriggerDeploy $triggerDeploy,
        private ServerSettings $serverSettings,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Executes the 5-step domain attachment & verification pipeline:
     * 1. Syntactic lint (valid FQDN, no IP literal, no local/reserved suffix)
     * 2. Collision check (free in DB, not the system host of gickers.sh)
     * 3. Public DNS check (points to the IP/host of gickers.sh)
     * 4. Docker network attachment + Reverse Proxy configuration
     * 5. HTTP Smoke test (2xx/3xx check, no 502)
     */
    public function add(
        ?User $actor,
        RepoName|string $repoName,
        string $ref,
        string $domainInput,
        string $serviceName,
        int $targetPort,
        bool $skipDnsCheck = false,
    ): DeployDomain {
        $repo = $this->accessResolver->assertCanManageDeploy($actor, $repoName, $ref);
        $environment = $this->deployStore->environmentByRef($repo, $ref);
        if (null === $environment) {
            throw new NotFound(sprintf('deployment environment "%s" not found (enable it first with `repo deploy enable`)', $ref));
        }

        // Step 1: Syntactic lint (enforced by DeployDomain::normalizeDomain & normalizeServiceName)
        $normalizedDomain = DeployDomain::normalizeDomain($domainInput);
        $normalizedService = DeployDomain::normalizeServiceName($serviceName);

        // Step 2: Collision check (system domain & DB uniqueness)
        $systemHost = $this->extractSystemHost();
        if (null !== $systemHost && $normalizedDomain === $systemHost) {
            throw new InvalidInput(sprintf('domain "%s" is reserved as the gickers.sh system domain', $normalizedDomain));
        }

        $existing = $this->deployStore->domainByName($normalizedDomain);
        if (null !== $existing) {
            if ($existing->environment()->id() === $environment->id()) {
                $existing->updateTarget($normalizedService, $targetPort, $this->clock->now());
                $domain = $existing;
            } else {
                throw new Conflict(sprintf('domain "%s" is already bound to another deployment environment', $normalizedDomain));
            }
        } else {
            $domain = new DeployDomain(
                environment: $environment,
                domain: $normalizedDomain,
                serviceName: $normalizedService,
                targetPort: $targetPort,
                createdAt: $this->clock->now(),
            );
        }

        // Step 3: Public DNS check
        if (!$skipDnsCheck) {
            $this->dnsVerifier->verifyPublicDns($normalizedDomain);
            $domain->markDnsVerified($this->clock->now());
        }

        $this->deployStore->saveDomain($domain);

        // Step 4 & 5: Reverse Proxy sync + Docker deployment / Smoke test
        $this->proxyRouter->syncDomains($this->deployStore->allActiveDomains());

        if (DeployStatus::Running === $environment->status()) {
            // Re-trigger deploy so the service container is attached to the proxy network if not already,
            // and run an immediate smoke test if already reachable.
            $status = $this->proxyRouter->smokeTest($domain);
            $domain->recordSmokeStatus($status, $this->clock->now());
            $this->deployStore->saveDomain($domain);

            if (null === $status || $status >= 500) {
                try {
                    ($this->triggerDeploy)($actor, $repo->name(), $environment->refName());
                } catch (\Throwable) {
                }
            }
        }

        return $domain;
    }

    public function get(?User $actor, RepoName|string $repoName, string $ref, string $domainInput): DeployDomain
    {
        $repo = $this->accessResolver->assertCanViewDeploy($actor, $repoName);
        $environment = $this->deployStore->environmentByRef($repo, $ref);
        if (null === $environment) {
            throw new NotFound(sprintf('deployment environment "%s" not found', $ref));
        }

        $domain = $this->deployStore->domainForEnvironment($environment, $domainInput);
        if (null === $domain) {
            throw new NotFound(sprintf('domain "%s" not found on environment "%s"', $domainInput, $ref));
        }

        return $domain;
    }

    public function remove(?User $actor, RepoName|string $repoName, string $ref, string $domainInput): void
    {
        $repo = $this->accessResolver->assertCanManageDeploy($actor, $repoName, $ref);
        $environment = $this->deployStore->environmentByRef($repo, $ref);
        if (null === $environment) {
            throw new NotFound(sprintf('deployment environment "%s" not found', $ref));
        }

        $domain = $this->deployStore->domainForEnvironment($environment, $domainInput);
        if (null === $domain) {
            throw new NotFound(sprintf('domain "%s" not found on environment "%s"', $domainInput, $ref));
        }

        $this->deployStore->removeDomain($domain);
        $this->proxyRouter->syncDomains($this->deployStore->allActiveDomains());
    }

    /**
     * @return list<DeployDomain>
     */
    public function list(?User $actor, RepoName|string $repoName, ?string $ref = null): array
    {
        $repo = $this->accessResolver->assertCanViewDeploy($actor, $repoName);
        if (null !== $ref && '' !== trim($ref)) {
            $environment = $this->deployStore->environmentByRef($repo, $ref);
            if (null === $environment) {
                throw new NotFound(sprintf('deployment environment "%s" not found', $ref));
            }

            return $environment->domains();
        }

        return $this->deployStore->domainsForRepo($repo);
    }

    private function extractSystemHost(): ?string
    {
        $parts = parse_url($this->serverSettings->httpPublicUrl);
        if (false === $parts || !isset($parts['host'])) {
            return null;
        }

        return strtolower(trim($parts['host']));
    }
}

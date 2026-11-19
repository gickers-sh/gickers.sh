<?php

declare(strict_types=1);

namespace App\Deploy\Domain\Model;

use App\Shared\Domain\Exception\InvalidInput;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'deploy_domains')]
#[ORM\UniqueConstraint(columns: ['domain'])]
class DeployDomain
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 253, unique: true)]
    private string $domain;

    #[ORM\Column(length: 128)]
    private string $serviceName;

    #[ORM\Column]
    private int $targetPort;

    #[ORM\Column]
    private bool $tlsEnabled = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dnsVerifiedAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $lastSmokeStatus = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: DeployEnvironment::class, inversedBy: 'domains')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private DeployEnvironment $environment,
        string $domain,
        string $serviceName,
        int $targetPort,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
        bool $tlsEnabled = true,
    ) {
        $this->domain = self::normalizeDomain($domain);
        $this->serviceName = self::normalizeServiceName($serviceName);
        if ($targetPort < 1 || $targetPort > 65535) {
            throw new InvalidInput(sprintf('invalid port %d (expected 1-65535)', $targetPort));
        }
        $this->targetPort = $targetPort;
        $this->tlsEnabled = $tlsEnabled;
        $this->updatedAt = $createdAt;
        $environment->addDomain($this);
    }

    public static function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = rtrim($domain, '.');

        if ('' === $domain || \strlen($domain) > 253) {
            throw new InvalidInput('domain name must be between 1 and 253 characters');
        }

        // Reject IP literals (IPv4 or IPv6)
        if (false !== filter_var($domain, \FILTER_VALIDATE_IP) || str_starts_with($domain, '[')) {
            throw new InvalidInput('domain must be a valid FQDN, not an IP address');
        }

        // Must contain at least one dot (FQDN) and no wildcards or localhost/internal suffixes
        if (!str_contains($domain, '.')) {
            throw new InvalidInput(sprintf('invalid FQDN "%s": must contain a top-level domain', $domain));
        }

        foreach (['.localhost', '.local', '.internal', '.invalid', '.test'] as $reserved) {
            if (str_ends_with($domain, $reserved) || $domain === ltrim($reserved, '.')) {
                throw new InvalidInput(sprintf('domain "%s" uses a reserved local/internal TLD', $domain));
            }
        }

        $labels = explode('.', $domain);
        foreach ($labels as $label) {
            if ('' === $label || \strlen($label) > 63 || !preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
                throw new InvalidInput(sprintf('invalid domain name "%s"', $domain));
            }
        }

        // Top-level label cannot be all-numeric
        $tld = $labels[\count($labels) - 1];
        if (ctype_digit($tld)) {
            throw new InvalidInput(sprintf('invalid top-level domain in "%s"', $domain));
        }

        return $domain;
    }

    public static function normalizeServiceName(string $service): string
    {
        $service = trim($service);
        if ('' === $service || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$/', $service)) {
            throw new InvalidInput(sprintf('invalid service name "%s"', $service));
        }

        return $service;
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('DeployDomain is not persisted yet.');
    }

    public function environment(): DeployEnvironment
    {
        return $this->environment;
    }

    public function domain(): string
    {
        return $this->domain;
    }

    public function serviceName(): string
    {
        return $this->serviceName;
    }

    public function targetPort(): int
    {
        return $this->targetPort;
    }

    public function isTlsEnabled(): bool
    {
        return $this->tlsEnabled;
    }

    public function dnsVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->dnsVerifiedAt;
    }

    public function lastSmokeStatus(): ?int
    {
        return $this->lastSmokeStatus;
    }

    public function updateTarget(string $serviceName, int $targetPort, \DateTimeImmutable $now): void
    {
        $this->serviceName = self::normalizeServiceName($serviceName);
        if ($targetPort < 1 || $targetPort > 65535) {
            throw new InvalidInput(sprintf('invalid port %d (expected 1-65535)', $targetPort));
        }
        $this->targetPort = $targetPort;
        $this->updatedAt = $now;
    }

    public function markDnsVerified(\DateTimeImmutable $now): void
    {
        $this->dnsVerifiedAt = $now;
        $this->updatedAt = $now;
    }

    public function recordSmokeStatus(?int $status, \DateTimeImmutable $now): void
    {
        $this->lastSmokeStatus = $status;
        $this->updatedAt = $now;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

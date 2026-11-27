<?php

declare(strict_types=1);

namespace App\Deploy\Domain\Model;

use App\Deploy\Domain\DeployRefType;
use App\Deploy\Domain\DeployStatus;
use App\Repo\Domain\Model\Repo;
use App\Shared\Domain\Exception\InvalidInput;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'deploy_environments')]
#[ORM\UniqueConstraint(columns: ['repo_id', 'ref_name'])]
class DeployEnvironment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $refName;

    #[ORM\Column(length: 16, enumType: DeployRefType::class)]
    private DeployRefType $refType;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column(length: 16, enumType: DeployStatus::class)]
    private DeployStatus $status = DeployStatus::Idle;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $deployedSha = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, DeployDomain> */
    #[ORM\OneToMany(targetEntity: DeployDomain::class, mappedBy: 'environment', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $domains;

    /** @var Collection<int, DeployEnvVar> */
    #[ORM\OneToMany(targetEntity: DeployEnvVar::class, mappedBy: 'environment', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $envVars;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Repo::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Repo $repo,
        string $refName,
        DeployRefType $refType,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $refName = self::normalizeRefName($refName);
        $this->refName = $refName;
        $this->refType = $refType;
        $this->updatedAt = $createdAt;
        $this->domains = new ArrayCollection();
        $this->envVars = new ArrayCollection();
    }

    public static function normalizeRefName(string $ref): string
    {
        $ref = trim($ref);
        if (str_starts_with($ref, 'refs/heads/')) {
            $ref = substr($ref, 11);
        } elseif (str_starts_with($ref, 'refs/tags/')) {
            $ref = substr($ref, 10);
        }

        if ('' === $ref || str_contains($ref, '..') || str_contains($ref, ' ') || str_starts_with($ref, '-')) {
            throw new InvalidInput(sprintf('invalid ref name "%s"', $ref));
        }

        return $ref;
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('DeployEnvironment is not persisted yet.');
    }

    public function repo(): Repo
    {
        return $this->repo;
    }

    public function refName(): string
    {
        return $this->refName;
    }

    public function refType(): DeployRefType
    {
        return $this->refType;
    }

    public function fullGitRef(): string
    {
        return match ($this->refType) {
            DeployRefType::Branch => 'refs/heads/'.$this->refName,
            DeployRefType::Tag => 'refs/tags/'.$this->refName,
        };
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled, \DateTimeImmutable $now): void
    {
        $this->enabled = $enabled;
        $this->updatedAt = $now;
    }

    public function status(): DeployStatus
    {
        return $this->status;
    }

    public function setStatus(DeployStatus $status, \DateTimeImmutable $now): void
    {
        $this->status = $status;
        $this->updatedAt = $now;
    }

    public function deployedSha(): ?string
    {
        return $this->deployedSha;
    }

    public function markDeployed(string $sha, \DateTimeImmutable $now): void
    {
        $this->deployedSha = $sha;
        $this->status = DeployStatus::Running;
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

    public function addDomain(DeployDomain $domain): void
    {
        if (!$this->domains->contains($domain)) {
            $this->domains->add($domain);
        }
    }

    public function removeDomainElement(DeployDomain $domain): void
    {
        $this->domains->removeElement($domain);
    }

    public function addEnvVar(DeployEnvVar $envVar): void
    {
        if (!$this->envVars->contains($envVar)) {
            $this->envVars->add($envVar);
        }
    }

    public function removeEnvVarElement(DeployEnvVar $envVar): void
    {
        $this->envVars->removeElement($envVar);
    }

    /** @return list<DeployDomain> */
    public function domains(): array
    {
        $items = array_values($this->domains->toArray());
        usort($items, static fn (DeployDomain $a, DeployDomain $b): int => strcmp($a->domain(), $b->domain()));

        return $items;
    }

    /** @return list<DeployEnvVar> */
    public function envVars(): array
    {
        $items = array_values($this->envVars->toArray());
        usort($items, static fn (DeployEnvVar $a, DeployEnvVar $b): int => strcmp($a->name(), $b->name()));

        return $items;
    }

    public function projectSlug(): string
    {
        $raw = sprintf('gickers_%d_%d', $this->repo->id(), $this->id());

        return strtolower((string) preg_replace('/[^a-zA-Z0-9_-]/', '_', $raw));
    }

    public function networkName(): string
    {
        return sprintf('gickers_env_%d', $this->id());
    }

    public function proxyAlias(string $serviceName): string
    {
        $cleanService = strtolower((string) preg_replace('/[^a-zA-Z0-9-]/', '-', $serviceName));

        return sprintf('gickers-env-%d-%s', $this->id(), $cleanService);
    }
}

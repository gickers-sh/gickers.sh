<?php

declare(strict_types=1);

namespace App\Deploy\Domain\Model;

use App\Deploy\Domain\DeployQuota;
use App\Repo\Domain\Model\Repo;
use App\Shared\Domain\Exception\InvalidInput;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'repo_deploy_ref_rules')]
#[ORM\UniqueConstraint(columns: ['repo_id', 'ref_pattern'])]
class RepoDeployRefRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $refPattern;

    #[ORM\Column(nullable: true)]
    private ?bool $allowedOverride = null;

    #[ORM\Column(nullable: true)]
    private ?float $quotaCpus = null;

    #[ORM\Column(nullable: true)]
    private ?int $quotaMemoryMb = null;

    #[ORM\Column(nullable: true)]
    private ?int $quotaPids = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxServices = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Repo::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Repo $repo,
        string $refPattern,
        \DateTimeImmutable $now,
    ) {
        $refPattern = trim($refPattern);
        if ('' === $refPattern) {
            throw new InvalidInput('ref pattern cannot be empty');
        }
        $this->refPattern = $refPattern;
        $this->updatedAt = $now;
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('RepoDeployRefRule is not persisted yet.');
    }

    public function repo(): Repo
    {
        return $this->repo;
    }

    public function refPattern(): string
    {
        return $this->refPattern;
    }

    public function allowedOverride(): ?bool
    {
        return $this->allowedOverride;
    }

    public function setAllowedOverride(?bool $allowed, \DateTimeImmutable $now): void
    {
        $this->allowedOverride = $allowed;
        $this->updatedAt = $now;
    }

    public function quotaCpus(): ?float
    {
        return $this->quotaCpus;
    }

    public function quotaMemoryMb(): ?int
    {
        return $this->quotaMemoryMb;
    }

    public function quotaPids(): ?int
    {
        return $this->quotaPids;
    }

    public function maxServices(): ?int
    {
        return $this->maxServices;
    }

    public function updateQuotas(
        ?float $cpus,
        ?int $memoryMb,
        ?int $pids,
        ?int $maxServices,
        \DateTimeImmutable $now,
    ): void {
        if (null !== $cpus) {
            $this->quotaCpus = $cpus;
        }
        if (null !== $memoryMb) {
            $this->quotaMemoryMb = $memoryMb;
        }
        if (null !== $pids) {
            $this->quotaPids = $pids;
        }
        if (null !== $maxServices) {
            $this->maxServices = $maxServices;
        }
        $this->updatedAt = $now;
    }

    public function resolveQuota(DeployQuota $base): DeployQuota
    {
        return new DeployQuota(
            $this->quotaCpus ?? $base->cpus,
            $this->quotaMemoryMb ?? $base->memoryMb,
            $this->quotaPids ?? $base->pids,
            $base->maxEnvironments,
            $this->maxServices ?? $base->maxServices,
        );
    }

    public function matches(string $ref): bool
    {
        if (fnmatch($this->refPattern, $ref)) {
            return true;
        }

        foreach (['refs/heads/', 'refs/tags/'] as $prefix) {
            if (str_starts_with($ref, $prefix)) {
                return fnmatch($this->refPattern, substr($ref, \strlen($prefix)));
            }
        }

        return false;
    }
}

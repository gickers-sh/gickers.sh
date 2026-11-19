<?php

declare(strict_types=1);

namespace App\Deploy\Domain\Model;

use App\Deploy\Domain\DeployQuota;
use App\Repo\Domain\Model\Repo;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'repo_deploy_configs')]
#[ORM\UniqueConstraint(columns: ['repo_id'])]
class RepoDeployConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?bool $allowedOverride = null;

    #[ORM\Column]
    private float $quotaCpus = DeployQuota::DEFAULT_CPUS;

    #[ORM\Column]
    private int $quotaMemoryMb = DeployQuota::DEFAULT_MEMORY_MB;

    #[ORM\Column]
    private int $quotaPids = DeployQuota::DEFAULT_PIDS;

    #[ORM\Column]
    private int $maxEnvironments = DeployQuota::DEFAULT_MAX_ENVIRONMENTS;

    #[ORM\Column]
    private int $maxServices = DeployQuota::DEFAULT_MAX_SERVICES;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\OneToOne(targetEntity: Repo::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Repo $repo,
        \DateTimeImmutable $now,
    ) {
        $this->updatedAt = $now;
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('RepoDeployConfig is not persisted yet.');
    }

    public function repo(): Repo
    {
        return $this->repo;
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

    public function quota(): DeployQuota
    {
        return new DeployQuota(
            $this->quotaCpus,
            $this->quotaMemoryMb,
            $this->quotaPids,
            $this->maxEnvironments,
            $this->maxServices,
        );
    }

    public function updateQuota(DeployQuota $quota, \DateTimeImmutable $now): void
    {
        $this->quotaCpus = $quota->cpus;
        $this->quotaMemoryMb = $quota->memoryMb;
        $this->quotaPids = $quota->pids;
        $this->maxEnvironments = $quota->maxEnvironments;
        $this->maxServices = $quota->maxServices;
        $this->updatedAt = $now;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

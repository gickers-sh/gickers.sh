<?php

declare(strict_types=1);

namespace App\Lfs\Domain\Model;

use App\Identity\Domain\Model\User;
use App\Repo\Domain\Model\Repo;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'lfs_locks')]
#[ORM\UniqueConstraint(columns: ['repo_id', 'path'])]
class LfsLock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Repo::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Repo $repo,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $owner,
        #[ORM\Column(length: 1024)]
        private string $path,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $refName,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('Lock is not persisted yet.');
    }

    public function repo(): Repo
    {
        return $this->repo;
    }

    public function owner(): User
    {
        return $this->owner;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function refName(): ?string
    {
        return $this->refName;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

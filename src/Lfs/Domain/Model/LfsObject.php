<?php

declare(strict_types=1);

namespace App\Lfs\Domain\Model;

use App\Repo\Domain\Model\Repo;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'lfs_objects')]
#[ORM\UniqueConstraint(columns: ['repo_id', 'oid'])]
class LfsObject
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Repo::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Repo $repo,
        #[ORM\Column(length: 64, options: ['fixed' => true])]
        private string $oid,
        #[ORM\Column(type: 'bigint')]
        private int $size,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public function repo(): Repo
    {
        return $this->repo;
    }

    public function oid(): string
    {
        return $this->oid;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

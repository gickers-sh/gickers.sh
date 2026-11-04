<?php

declare(strict_types=1);

namespace App\Repo\Domain\Model;

use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'repo_collaborators')]
#[ORM\UniqueConstraint(columns: ['repo_id', 'user_id'])]
class Collaborator
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Repo::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Repo $repo,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(type: 'smallint', enumType: AccessLevel::class)]
        private AccessLevel $accessLevel,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->updatedAt = $createdAt;
    }

    public function repo(): Repo
    {
        return $this->repo;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function accessLevel(): AccessLevel
    {
        return $this->accessLevel;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function changeAccessLevel(AccessLevel $level, \DateTimeImmutable $now): void
    {
        $this->accessLevel = $level;
        $this->updatedAt = $now;
    }
}

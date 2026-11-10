<?php

declare(strict_types=1);

namespace App\Repo\Domain\Model;

use App\Identity\Domain\Model\User;
use App\Repo\Domain\RepoName;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'repos')]
class Repo
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private string $name;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(options: ['default' => false])]
    private bool $requireSignedCommits = false;

    #[ORM\Column(options: ['default' => 0])]
    private int $cloneCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $fetchCount = 0;

    /** @var Collection<int, BranchProtection> */
    #[ORM\OneToMany(targetEntity: BranchProtection::class, mappedBy: 'repo', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $branchProtections;

    public function __construct(
        RepoName $name,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private User $owner,
        #[ORM\Column(length: 255)]
        private string $projectName,
        #[ORM\Column(type: 'text')]
        private string $description,
        #[ORM\Column]
        private bool $isPrivate,
        #[ORM\Column]
        private bool $isHidden,
        #[ORM\Column]
        private bool $isMirror,
        #[ORM\ManyToOne(targetEntity: self::class)]
        #[ORM\JoinColumn(onDelete: 'SET NULL')]
        private ?self $forkOf,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->name = $name->value;
        $this->updatedAt = $createdAt;
        $this->branchProtections = new ArrayCollection();
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('Repo is not persisted yet.');
    }

    public function name(): RepoName
    {
        return RepoName::fromInput($this->name);
    }

    public function nameString(): string
    {
        return $this->name;
    }

    public function owner(): User
    {
        return $this->owner;
    }

    public function projectName(): string
    {
        return $this->projectName;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function isPrivate(): bool
    {
        return $this->isPrivate;
    }

    public function isHidden(): bool
    {
        return $this->isHidden;
    }

    public function isMirror(): bool
    {
        return $this->isMirror;
    }

    public function forkOf(): ?self
    {
        return $this->forkOf;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isOwnedBy(?User $user): bool
    {
        return null !== $user && $this->owner->id() === $user->id();
    }

    public function rename(RepoName $name, \DateTimeImmutable $now): void
    {
        $this->name = $name->value;
        $this->touch($now);
    }

    public function describe(string $description, \DateTimeImmutable $now): void
    {
        $this->description = $description;
        $this->touch($now);
    }

    public function changeProjectName(string $projectName, \DateTimeImmutable $now): void
    {
        $this->projectName = $projectName;
        $this->touch($now);
    }

    public function setPrivate(bool $isPrivate, \DateTimeImmutable $now): void
    {
        $this->isPrivate = $isPrivate;
        $this->touch($now);
    }

    public function setHidden(bool $isHidden, \DateTimeImmutable $now): void
    {
        $this->isHidden = $isHidden;
        $this->touch($now);
    }

    public function setMirror(bool $isMirror, \DateTimeImmutable $now): void
    {
        $this->isMirror = $isMirror;
        $this->touch($now);
    }

    public function requireSignedCommits(): bool
    {
        return $this->requireSignedCommits;
    }

    public function setRequireSignedCommits(bool $require, \DateTimeImmutable $now): void
    {
        $this->requireSignedCommits = $require;
        $this->touch($now);
    }

    public function cloneCount(): int
    {
        return $this->cloneCount;
    }

    public function fetchCount(): int
    {
        return $this->fetchCount;
    }

    /**
     * @return list<BranchProtection>
     */
    public function branchProtections(): array
    {
        return array_values($this->branchProtections->toArray());
    }

    public function branchProtectionFor(string $pattern): ?BranchProtection
    {
        foreach ($this->branchProtections as $rule) {
            if ($rule->pattern() === $pattern) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Creates or replaces the protection rule for the given pattern.
     */
    public function protectBranch(string $pattern, bool $noForce, bool $noDelete, bool $adminsOnly, \DateTimeImmutable $now): void
    {
        $existing = $this->branchProtectionFor($pattern);
        if (null !== $existing) {
            $existing->update($noForce, $noDelete, $adminsOnly);
        } else {
            $this->branchProtections->add(new BranchProtection($this, $pattern, $noForce, $noDelete, $adminsOnly));
        }
        $this->touch($now);
    }

    /**
     * Removes the protection rule matching the pattern. Returns whether a rule was removed.
     */
    public function unprotectBranch(string $pattern, \DateTimeImmutable $now): bool
    {
        $existing = $this->branchProtectionFor($pattern);
        if (null === $existing) {
            return false;
        }
        $this->branchProtections->removeElement($existing);
        $this->touch($now);

        return true;
    }

    public function touch(\DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }
}

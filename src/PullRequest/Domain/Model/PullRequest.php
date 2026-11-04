<?php

declare(strict_types=1);

namespace App\PullRequest\Domain\Model;

use App\Identity\Domain\Model\User;
use App\PullRequest\Domain\PullRequestStatus;
use App\Repo\Domain\Model\Repo;
use App\Shared\Domain\Exception\Conflict;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'pull_requests')]
#[ORM\UniqueConstraint(columns: ['repo_id', 'number'])]
#[ORM\Index(columns: ['repo_id', 'status'])]
class PullRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: PullRequestStatus::class)]
    private PullRequestStatus $status = PullRequestStatus::Open;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $mergeCommit = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $mergedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $mergedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Repo::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Repo $repo,
        #[ORM\Column]
        private int $number,
        #[ORM\Column(length: 255)]
        private string $title,
        #[ORM\Column(type: 'text')]
        private string $description,
        #[ORM\Column(length: 255)]
        private string $baseRef,
        #[ORM\Column(length: 64)]
        private string $baseCommit,
        #[ORM\ManyToOne(targetEntity: Repo::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Repo $headRepo,
        #[ORM\Column(length: 255)]
        private string $headRef,
        #[ORM\Column(length: 64)]
        private string $headCommit,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $author,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->updatedAt = $createdAt;
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('Pull request is not persisted yet.');
    }

    public function repo(): Repo
    {
        return $this->repo;
    }

    public function number(): int
    {
        return $this->number;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function status(): PullRequestStatus
    {
        return $this->status;
    }

    public function isOpen(): bool
    {
        return PullRequestStatus::Open === $this->status;
    }

    public function isMerged(): bool
    {
        return PullRequestStatus::Merged === $this->status;
    }

    public function baseRef(): string
    {
        return $this->baseRef;
    }

    public function baseCommit(): string
    {
        return $this->baseCommit;
    }

    public function headRepo(): Repo
    {
        return $this->headRepo;
    }

    public function headRef(): string
    {
        return $this->headRef;
    }

    public function headCommit(): string
    {
        return $this->headCommit;
    }

    public function author(): User
    {
        return $this->author;
    }

    public function mergeCommit(): ?string
    {
        return $this->mergeCommit;
    }

    public function mergedBy(): ?User
    {
        return $this->mergedBy;
    }

    public function mergedAt(): ?\DateTimeImmutable
    {
        return $this->mergedAt;
    }

    public function closedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isAuthoredBy(?User $user): bool
    {
        return null !== $user && $this->author->id() === $user->id();
    }

    public function edit(string $title, string $description, \DateTimeImmutable $now): void
    {
        $this->title = $title;
        $this->description = $description;
        $this->updatedAt = $now;
    }

    public function updateHead(string $headCommit, \DateTimeImmutable $now): void
    {
        $this->headCommit = $headCommit;
        $this->updatedAt = $now;
    }

    public function merge(User $by, string $mergeCommit, \DateTimeImmutable $now): void
    {
        $this->assertStatus(PullRequestStatus::Open);
        $this->status = PullRequestStatus::Merged;
        $this->mergedBy = $by;
        $this->mergeCommit = $mergeCommit;
        $this->mergedAt = $now;
        $this->updatedAt = $now;
    }

    public function close(\DateTimeImmutable $now): void
    {
        $this->assertStatus(PullRequestStatus::Open);
        $this->status = PullRequestStatus::Closed;
        $this->closedAt = $now;
        $this->updatedAt = $now;
    }

    public function reopen(string $headCommit, \DateTimeImmutable $now): void
    {
        $this->assertStatus(PullRequestStatus::Closed);
        $this->status = PullRequestStatus::Open;
        $this->closedAt = null;
        $this->headCommit = $headCommit;
        $this->updatedAt = $now;
    }

    private function assertStatus(PullRequestStatus $expected): void
    {
        if ($this->status !== $expected) {
            // Go `pkg/backend/pr.go` surfaces plain `pull request is not open|closed`.
            throw new Conflict(\sprintf('pull request is not %s', $expected->value));
        }
    }
}

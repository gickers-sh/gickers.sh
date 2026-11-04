<?php

declare(strict_types=1);

namespace App\PullRequest\Domain\Model;

use App\Identity\Domain\Model\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'pull_request_comments')]
class Comment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: PullRequest::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private PullRequest $pullRequest,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $author,
        #[ORM\Column(type: 'text')]
        private string $body,
        #[ORM\Column(length: 64, nullable: true)]
        private ?string $commitSha,
        #[ORM\Column(length: 1024, nullable: true)]
        private ?string $filePath,
        #[ORM\Column(nullable: true)]
        private ?int $line,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->updatedAt = $createdAt;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function pullRequest(): PullRequest
    {
        return $this->pullRequest;
    }

    public function author(): User
    {
        return $this->author;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function commitSha(): ?string
    {
        return $this->commitSha;
    }

    public function filePath(): ?string
    {
        return $this->filePath;
    }

    public function line(): ?int
    {
        return $this->line;
    }

    public function isLineComment(): bool
    {
        return null !== $this->filePath && null !== $this->line;
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

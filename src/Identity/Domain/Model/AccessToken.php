<?php

declare(strict_types=1);

namespace App\Identity\Domain\Model;

use Doctrine\ORM\Mapping as ORM;

/**
 * Personal access token; only the sha256 of the secret is stored.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_access_tokens')]
class AccessToken
{
    public const PREFIX = 'ss_';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 255)]
        private string $name,
        #[ORM\Column(length: 64, unique: true)]
        private string $tokenHash,
        #[ORM\Column(nullable: true)]
        private ?\DateTimeImmutable $expiresAt,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    /** @return array{AccessToken, string} the entity and the one-time plaintext secret */
    public static function issue(User $user, string $name, ?\DateTimeImmutable $expiresAt, \DateTimeImmutable $now): array
    {
        $secret = self::PREFIX.bin2hex(random_bytes(20));

        return [new self($user, $name, self::hash($secret), $expiresAt, $now), $secret];
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('Token is not persisted yet.');
    }

    public function user(): User
    {
        return $this->user;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function expiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function lastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return null !== $this->expiresAt && $this->expiresAt <= $now;
    }

    public function markUsed(\DateTimeImmutable $now): void
    {
        $this->lastUsedAt = $now;
    }
}

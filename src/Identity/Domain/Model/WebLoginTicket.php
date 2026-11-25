<?php

declare(strict_types=1);

namespace App\Identity\Domain\Model;

use Doctrine\ORM\Mapping as ORM;

/**
 * Single-use ticket for the web login flow.
 * Once used (redeemed) or expired, it cannot be used again.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_web_login_tickets')]
class WebLoginTicket
{
    public const PREFIX = 'wlt_';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 64, unique: true)]
        private string $tokenHash,
        #[ORM\Column]
        private \DateTimeImmutable $expiresAt,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @return array{WebLoginTicket, string} the entity and the one-time plaintext token secret
     */
    public static function issue(User $user, \DateTimeImmutable $now, int $ttlSeconds = 600): array
    {
        $secret = self::PREFIX . bin2hex(random_bytes(24));
        $expiresAt = $now->modify(sprintf('+%d seconds', $ttlSeconds));

        return [
            new self($user, self::hash($secret), $expiresAt, $now),
            $secret,
        ];
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('Ticket is not persisted yet.');
    }

    public function user(): User
    {
        return $this->user;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function usedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function isUsed(): bool
    {
        return null !== $this->usedAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    public function markUsed(\DateTimeImmutable $now): void
    {
        $this->usedAt = $now;
    }
}

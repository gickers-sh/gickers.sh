<?php

declare(strict_types=1);

namespace App\Identity\Domain\Model;

use App\Shared\Domain\Exception\InvalidInput;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'users')]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $username;

    #[ORM\Column(nullable: true)]
    private ?string $passwordHash = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $webTokenVersion = 0;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, PublicKey> */
    #[ORM\OneToMany(targetEntity: PublicKey::class, mappedBy: 'user', cascade: ['persist'], orphanRemoval: true)]
    private Collection $publicKeys;

    public function __construct(
        string $username,
        #[ORM\Column]
        private bool $isAdmin,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->username = self::normalizeUsername($username);
        $this->updatedAt = $createdAt;
        $this->publicKeys = new ArrayCollection();
    }

    /**
     * Go `utils.ValidateUsername`: starts with a letter, then letters, digits and hyphens; stored lowercase.
     */
    public static function normalizeUsername(string $username): string
    {
        $username = strtolower(trim($username));
        if ('' === $username) {
            throw new InvalidInput('username cannot be empty');
        }
        if (!preg_match('/^\\p{L}/u', $username)) {
            throw new InvalidInput('username must start with a letter');
        }
        if (!preg_match('/^[\\p{L}\\p{N}-]+$/u', $username)) {
            throw new InvalidInput('username can only contain letters, numbers, and hyphens');
        }

        return $username;
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('User is not persisted yet.');
    }

    public function username(): string
    {
        return $this->username;
    }

    public function isAdmin(): bool
    {
        return $this->isAdmin;
    }

    public function passwordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return list<PublicKey> */
    public function publicKeys(): array
    {
        // Sorted here: ORM 3.7 deprecates string directions in #[OrderBy] without a usable replacement.
        $keys = array_values($this->publicKeys->toArray());
        usort($keys, static fn(PublicKey $a, PublicKey $b): int => ($a->id() ?? \PHP_INT_MAX) <=> ($b->id() ?? \PHP_INT_MAX));

        return $keys;
    }

    public function rename(string $username, \DateTimeImmutable $now): void
    {
        $this->username = self::normalizeUsername($username);
        $this->updatedAt = $now;
    }

    public function setAdmin(bool $isAdmin, \DateTimeImmutable $now): void
    {
        $this->isAdmin = $isAdmin;
        $this->updatedAt = $now;
    }

    public function changePasswordHash(?string $passwordHash, \DateTimeImmutable $now): void
    {
        $this->passwordHash = $passwordHash;
        $this->updatedAt = $now;
    }

    public function webTokenVersion(): int
    {
        return $this->webTokenVersion;
    }

    /**
     * Invalidates every outstanding web-session cookie by bumping the version
     * carried in their JWT `wtv` claim (the `web logout` command).
     */
    public function revokeWebSessions(\DateTimeImmutable $now): void
    {
        ++$this->webTokenVersion;
        $this->updatedAt = $now;
    }

    public function addPublicKey(SshKey $key, \DateTimeImmutable $now): PublicKey
    {
        foreach ($this->publicKeys as $existing) {
            if ($existing->fingerprint() === $key->fingerprint) {
                return $existing;
            }
        }
        $publicKey = new PublicKey($this, $key, $now);
        $this->publicKeys->add($publicKey);
        $this->updatedAt = $now;

        return $publicKey;
    }

    public function removePublicKey(SshKey $key, \DateTimeImmutable $now): bool
    {
        foreach ($this->publicKeys as $existing) {
            if ($existing->fingerprint() === $key->fingerprint) {
                $this->publicKeys->removeElement($existing);
                $this->updatedAt = $now;

                return true;
            }
        }

        return false;
    }
}

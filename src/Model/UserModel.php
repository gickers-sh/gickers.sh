<?php

declare(strict_types=1);

namespace App\Model;

/**
 * UserModel represents a Soft Serve user with their public keys.
 */
final class UserModel
{
    /**
     * @param list<string> $publicKeys
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $username,
        public readonly bool $isAdmin = false,
        public readonly array $publicKeys = [],
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?\DateTimeImmutable $updatedAt = null,
    ) {
    }

    /**
     * Returns a copy of the user model with updated public keys.
     *
     * @param list<string> $publicKeys
     */
    public function withPublicKeys(array $publicKeys): self
    {
        return new self(
            $this->id,
            $this->username,
            $this->isAdmin,
            $publicKeys,
            $this->createdAt,
            $this->updatedAt,
        );
    }
}

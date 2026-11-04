<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\Model\User;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class SecurityUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    public function __construct(private User $user)
    {
    }

    public function user(): User
    {
        return $this->user;
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        /** @var non-empty-string */
        return $this->user->username();
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return $this->user->isAdmin() ? ['ROLE_ADMIN', 'ROLE_USER'] : ['ROLE_USER'];
    }

    public function getPassword(): ?string
    {
        return $this->user->passwordHash();
    }

    public function eraseCredentials(): void
    {
    }
}

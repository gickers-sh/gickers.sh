<?php

declare(strict_types=1);

namespace App\Access\Infrastructure\Security;

use App\Access\Domain\Permission;
use App\Identity\Domain\Model\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, mixed>
 */
final class ServerAdminVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return Permission::SERVER_ADMIN === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if ($user instanceof SecurityUser) {
            return $user->user()->isAdmin();
        }

        if ($user instanceof User) {
            return $user->isAdmin();
        }

        return false;
    }
}

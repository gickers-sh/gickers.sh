<?php

declare(strict_types=1);

namespace App\Access\Infrastructure\Security;

use App\Access\Application\AccessResolver;
use App\Access\Domain\Permission;
use App\Identity\Domain\Model\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, RepoName|Repo|string>
 */
final class RepoVoter extends Voter
{
    public function __construct(
        private AccessResolver $accessResolver,
        private RepoStore $repoStore,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, [
            Permission::REPO_READ,
            Permission::REPO_WRITE,
            Permission::REPO_ADMIN,
            Permission::REPO_CREATE,
        ], true)) {
            return false;
        }

        return $subject instanceof RepoName || $subject instanceof Repo || is_string($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $name = match (true) {
            $subject instanceof RepoName => $subject,
            $subject instanceof Repo => $subject->name(),
            default => RepoName::fromInput($subject),
        };

        $securityUser = $token->getUser();
        $user = match (true) {
            $securityUser instanceof SecurityUser => $securityUser->user(),
            $securityUser instanceof User => $securityUser,
            default => null,
        };

        $level = $this->accessResolver->levelFor($user, $name);

        return match ($attribute) {
            Permission::REPO_READ => $level->canRead(),
            Permission::REPO_WRITE => $level->canWrite(),
            Permission::REPO_ADMIN => $level->isAdmin(),
            Permission::REPO_CREATE => null === $this->repoStore->byName($name) && $level->canWrite(),
            default => false,
        };
    }
}

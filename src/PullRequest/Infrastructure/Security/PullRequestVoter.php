<?php

declare(strict_types=1);

namespace App\PullRequest\Infrastructure\Security;

use App\Access\Application\AccessResolver;
use App\Identity\Domain\Model\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\PullRequest\Domain\Model\PullRequest;
use App\PullRequest\Domain\PullRequestPermission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Authorises pull request operations against the ambient security token.
 *
 * Mirrors Go `pkg/backend/pr.go` gating: viewing needs repository read access,
 * merging needs write access, and edit/close/reopen/delete are allowed for the
 * pull request author or anyone with repository write access.
 *
 * @extends Voter<string, PullRequest>
 */
final class PullRequestVoter extends Voter
{
    public function __construct(private AccessResolver $accessResolver) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof PullRequest && \in_array($attribute, [
            PullRequestPermission::PR_VIEW,
            PullRequestPermission::PR_EDIT,
            PullRequestPermission::PR_CLOSE,
            PullRequestPermission::PR_REOPEN,
            PullRequestPermission::PR_DELETE,
            PullRequestPermission::PR_MERGE,
        ], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        \assert($subject instanceof PullRequest);

        $securityUser = $token->getUser();
        $user = match (true) {
            $securityUser instanceof SecurityUser => $securityUser->user(),
            $securityUser instanceof User => $securityUser,
            default => null,
        };

        $repo = $subject->repo();
        $level = $this->accessResolver->levelFor($user, $repo->name(), $repo);

        return match ($attribute) {
            PullRequestPermission::PR_VIEW => $level->canRead(),
            PullRequestPermission::PR_MERGE => $level->canWrite(),
            PullRequestPermission::PR_EDIT,
            PullRequestPermission::PR_CLOSE,
            PullRequestPermission::PR_REOPEN,
            PullRequestPermission::PR_DELETE => $subject->isAuthoredBy($user) || $level->canWrite(),
            default => false,
        };
    }
}

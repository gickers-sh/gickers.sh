<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\AccessTokenStore;
use App\Identity\Domain\Model\AccessToken;
use App\Identity\Domain\Model\User;
use App\Shared\Domain\Exception\AccessDenied;
use Psr\Clock\ClockInterface;

final readonly class IssueAccessToken
{
    public function __construct(
        private AccessTokenStore $accessTokenStore,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $scopes
     * @param list<string> $repos
     * @return array{AccessToken, string}
     */
    public function __invoke(
        ?User $actor,
        string $name,
        ?\DateTimeImmutable $expiresAt = null,
        array $scopes = ['*'],
        array $repos = [],
        ?string $description = null,
        ?User $targetUser = null,
        bool $singleUse = false,
    ): array {
        if (null === $actor) {
            throw new AccessDenied('unauthorized');
        }

        $owner = $actor;
        if (null !== $targetUser && $targetUser->id() !== $actor->id()) {
            if (!$actor->isAdmin()) {
                throw new AccessDenied('only admins can issue tokens for other users');
            }
            $owner = $targetUser;
        }

        [$token, $secret] = AccessToken::issue(
            $owner,
            $name,
            $expiresAt,
            $this->clock->now(),
            $scopes,
            $repos,
            $description,
            $singleUse,
        );
        $this->accessTokenStore->save($token);

        return [$token, $secret];
    }
}

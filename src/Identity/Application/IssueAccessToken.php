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
     * @return array{AccessToken, string}
     */
    public function __invoke(?User $actor, string $name, ?\DateTimeImmutable $expiresAt = null): array
    {
        if (null === $actor) {
            throw new AccessDenied('unauthorized');
        }

        [$token, $secret] = AccessToken::issue($actor, $name, $expiresAt, $this->clock->now());
        $this->accessTokenStore->save($token);

        return [$token, $secret];
    }
}

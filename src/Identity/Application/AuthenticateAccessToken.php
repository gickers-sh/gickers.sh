<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\AccessTokenStore;
use App\Identity\Domain\Model\AccessToken;
use App\Identity\Domain\Model\User;
use App\Shared\Domain\Exception\AccessDenied;
use Psr\Clock\ClockInterface;

final readonly class AuthenticateAccessToken
{
    public function __construct(
        private AccessTokenStore $accessTokenStore,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $secret): ?User
    {
        $hash = AccessToken::hash($secret);
        $token = $this->accessTokenStore->byHash($hash);
        if (null === $token) {
            return null;
        }

        $now = $this->clock->now();
        if ($token->isExpired($now)) {
            throw new AccessDenied('token expired');
        }

        $token->markUsed($now);
        $this->accessTokenStore->save($token);

        return $token->user();
    }
}

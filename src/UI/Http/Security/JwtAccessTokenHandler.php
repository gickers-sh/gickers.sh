<?php

declare(strict_types=1);

namespace App\UI\Http\Security;

use App\Identity\Domain\UserStore;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Lfs\Domain\TokenVerifier;
use App\Shared\Infrastructure\Config\ServerSettings;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Validates Git LFS bearer JWT tokens (Authorization: Bearer <token>) for the Symfony access_token firewall.
 * Enforces EdDSA signature, issuer (httpPublicUrl), audience (the target repository), and lifetime.
 * Accepts both `sub=userId` (IssueLfsToken contract) and `sub=username#id` (Go SSH jwt command parity).
 */
final readonly class JwtAccessTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private TokenVerifier $verifier,
        private UserStore $userStore,
        private ServerSettings $settings,
        private RequestStack $requestStack,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $request = $this->requestStack->getCurrentRequest();
        $repo = '';
        if (null !== $request) {
            $attr = $request->attributes->get('repo');
            if (\is_string($attr) && '' !== $attr) {
                $repo = preg_replace('/\.git$/', '', $attr) ?? $attr;
            } else {
                $path = $request->getPathInfo();
                if (preg_match('#^/([^/]+?)(?:\.git)?(?:/.*)?$#', $path, $m)) {
                    $repo = $m[1];
                }
            }
        }

        try {
            $subject = $this->verifier->verify($accessToken, $this->settings->httpPublicUrl, $repo);
        } catch (\Throwable $e) {
            throw new BadCredentialsException('Invalid access token: '.$e->getMessage(), 0, $e);
        }

        if (str_contains($subject, '#')) {
            [$username, $id] = explode('#', $subject, 2);
            $user = $this->userStore->byUsername($username);
            if (null === $user || (string) $user->id() !== $id) {
                throw new BadCredentialsException('Invalid token subject.');
            }
        } elseif (ctype_digit($subject)) {
            $user = $this->userStore->byId((int) $subject);
            if (null === $user) {
                throw new BadCredentialsException('User not found.');
            }
        } else {
            $user = $this->userStore->byUsername($subject);
            if (null === $user) {
                throw new BadCredentialsException('User not found.');
            }
        }

        return new UserBadge($user->username(), static fn () => new SecurityUser($user));
    }
}

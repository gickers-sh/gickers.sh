<?php

declare(strict_types=1);

namespace App\UI\Http\Security;

use App\Identity\Application\AuthenticateAccessToken;
use App\Identity\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Validates bearer access tokens (Authorization: Bearer <token>) for the Symfony access_token firewall.
 * Supports Personal Access Tokens and ephemeral tokens with repository and scope restrictions.
 */
final readonly class BearerAccessTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private RequestStack $requestStack,
        private AuthenticateAccessToken $authenticateAccessToken,
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

        $token = $this->authenticateAccessToken->findToken($accessToken);
        if (null === $token) {
            throw new BadCredentialsException('Invalid access token.');
        }

        if ('' !== $repo && !$token->allowsRepo($repo)) {
            throw new BadCredentialsException(sprintf('Access token is not authorized for repository "%s".', $repo));
        }

        $path = $request?->getPathInfo() ?? '';
        $isLfs = str_contains($path, '/info/lfs') || 'application/vnd.git-lfs+json' === $request?->headers->get('Accept');
        if ($isLfs && !$token->hasScope('lfs') && !$token->hasScope('*') && !$token->hasScope('all') && !$token->hasScope('write') && !$token->hasScope('read')) {
            throw new BadCredentialsException('Access token missing required scope for Git LFS.');
        }

        $user = $token->user();

        return new UserBadge($user->username(), static fn () => new SecurityUser($user));
    }
}

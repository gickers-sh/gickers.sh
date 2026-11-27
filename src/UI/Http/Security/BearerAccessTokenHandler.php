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
                $repo = preg_replace('/\.git$/i', '', trim($attr, '/')) ?? $attr;
            } else {
                $path = $request->getPathInfo();
                if (preg_match('#^/(.+?)(?:\.git)?(?:/info/lfs(?:/.*)?|/info/refs|/git-(?:upload|receive)-pack)$#', $path, $m)) {
                    $repo = $m[1];
                } elseif (preg_match('#^/([^/]+?)(?:\.git)?(?:/.*)?$#', $path, $m)) {
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
        if ($isLfs) {
            $isWriteLfs = false;
            if (null !== $request) {
                $method = strtoupper($request->getMethod());
                if (\in_array($method, ['PUT', 'DELETE'], true)) {
                    $isWriteLfs = true;
                } elseif ('POST' === $method && (str_contains($path, '/verify') || str_contains($path, '/locks'))) {
                    $isWriteLfs = true;
                } elseif ('POST' === $method && str_contains($path, '/objects/batch')) {
                    $decoded = json_decode($request->getContent(), true);
                    if (\is_array($decoded) && 'upload' === ($decoded['operation'] ?? null)) {
                        $isWriteLfs = true;
                    }
                }
            }

            $requiredScope = $isWriteLfs ? 'lfs:upload' : 'lfs:download';
            if (!$token->hasScope($requiredScope)) {
                throw new BadCredentialsException('Access token missing required scope for Git LFS.');
            }
        } else {
            if ($token->isEphemeralLfsOnly()) {
                throw new BadCredentialsException('Git LFS ephemeral token cannot be used outside Git LFS endpoints.');
            }
            $isPush = str_contains($path, 'git-receive-pack') || 'git-receive-pack' === $request?->query->get('service');
            if ($isPush && !$token->hasScope('repo:write')) {
                throw new BadCredentialsException('Access token missing required write scope.');
            }
            if (!$isPush && !$token->hasScope('repo:read')) {
                throw new BadCredentialsException('Access token missing required read scope.');
            }
        }

        $user = $token->user();

        return new UserBadge($user->username(), static fn () => new SecurityUser($user));
    }
}

<?php

declare(strict_types=1);

namespace App\UI\Http\Security;

use App\Identity\Application\AuthenticateAccessToken;
use App\Identity\Domain\UserStore;
use App\Identity\Infrastructure\Security\SecurityUser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authenticates Git HTTP requests using Basic auth (username:password or username:token or token)
 * or Token auth (Authorization: Token <token>), matching Go `pkg/web/auth.go:parseAuthHdr`.
 *
 * Failed credentials set the `_bad_credentials` request attribute and fall through to anonymous,
 * so that `denyRead` / `GitAccessAuthorizer` can return the appropriate 401 or 403 response
 * without revealing private repository existence.
 */
final class AccessTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly UserStore $userStore,
        private readonly AuthenticateAccessToken $authenticateAccessToken,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function supports(Request $request): bool
    {
        $auth = $request->headers->get('Authorization');
        if (null !== $auth) {
            $lower = strtolower($auth);
            if (str_starts_with($lower, 'basic ') || str_starts_with($lower, 'token ')) {
                return true;
            }
        }

        return null !== $request->getUser() || null !== $request->headers->get('php-auth-user');
    }

    public function authenticate(Request $request): Passport
    {
        $auth = $request->headers->get('Authorization');

        if (null !== $auth && str_starts_with(strtolower($auth), 'token ')) {
            $secret = trim(substr($auth, 6));
            $user = ($this->authenticateAccessToken)($secret);
            if (null === $user) {
                throw new BadCredentialsException('Invalid access token.');
            }

            return new SelfValidatingPassport(new UserBadge($user->username(), static fn () => new SecurityUser($user)));
        }

        // Basic auth
        $username = $request->getUser() ?? '';
        $password = $request->getPassword() ?? '';

        if (null !== $auth && str_starts_with(strtolower($auth), 'basic ')) {
            $decoded = base64_decode(substr(trim($auth), 6), true);
            if (false !== $decoded && str_contains($decoded, ':')) {
                [$u, $p] = explode(':', $decoded, 2);
                $username = $u;
                $password = $p;
            } elseif (false !== $decoded) {
                $username = $decoded;
                $password = '';
            }
        }

        if ('' !== $username && '' !== $password) {
            $user = $this->userStore->byUsername($username);
            if (null !== $user) {
                if ($this->passwordHasher->isPasswordValid(new SecurityUser($user), $password)) {
                    return new SelfValidatingPassport(new UserBadge($user->username(), static fn () => new SecurityUser($user)));
                }

                // Check if password is an access token for this or another user
                $tokenUser = ($this->authenticateAccessToken)($password);
                if (null !== $tokenUser) {
                    return new SelfValidatingPassport(new UserBadge($tokenUser->username(), static fn () => new SecurityUser($tokenUser)));
                }

                throw new BadCredentialsException('Invalid password or token.');
            }

            // Username not found, check if password is an access token
            $tokenUser = ($this->authenticateAccessToken)($password);
            if (null !== $tokenUser) {
                return new SelfValidatingPassport(new UserBadge($tokenUser->username(), static fn () => new SecurityUser($tokenUser)));
            }

            throw new BadCredentialsException('User not found and invalid token.');
        }

        if ('' !== $username) {
            // Try username as an access token
            $tokenUser = ($this->authenticateAccessToken)($username);
            if (null !== $tokenUser) {
                return new SelfValidatingPassport(new UserBadge($tokenUser->username(), static fn () => new SecurityUser($tokenUser)));
            }

            throw new BadCredentialsException('Invalid access token.');
        }

        throw new BadCredentialsException('Missing credentials.');
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // Mark bad credentials so authorizer/denyRead can answer 403 or 401 accordingly
        $request->attributes->set('_bad_credentials', true);

        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\UI\Http\Security;

use App\Identity\Application\AuthenticateAccessToken;
use App\Identity\Domain\Model\AccessToken;
use App\Identity\Domain\UserStore;
use App\Identity\Infrastructure\Security\LoginThrottler;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Identity\Infrastructure\WebAuth\WebSessionTokens;
use App\Shared\Domain\Exception\AccessDenied;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
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
        private readonly WebSessionTokens $webSessionTokens,
        private readonly LoginThrottler $loginThrottler,
    ) {}

    public function supports(Request $request): bool
    {
        $auth = $request->headers->get('Authorization');
        if (null !== $auth) {
            $lower = strtolower($auth);
            if (str_starts_with($lower, 'basic ') || str_starts_with($lower, 'token ')) {
                return true;
            }
        }

        if (null !== $request->getUser() || null !== $request->headers->get('php-auth-user')) {
            return true;
        }

        // Browser page views carry no Authorization header; identity rides in the
        // `web login` session cookie instead.
        return $request->cookies->has(WebSessionTokens::COOKIE_NAME);
    }

    public function authenticate(Request $request): Passport
    {
        $auth = $request->headers->get('Authorization');

        // Cookie-only browser request (no Basic/Token credentials): authenticate the
        // `web login` session. Invalid cookies fall through to anonymous via onAuthenticationFailure.
        $hasHeaderCreds = null !== $auth
            || null !== $request->getUser()
            || null !== $request->headers->get('php-auth-user');
        if (!$hasHeaderCreds) {
            return $this->authenticateSessionCookie($request);
        }

        $repo = $this->extractRepoName($request);

        if (null !== $auth && str_starts_with(strtolower($auth), 'token ')) {
            $secret = trim(substr($auth, 6));
            $clientIp = $request->getClientIp() ?? '127.0.0.1';
            $throttleTarget = 'token:' . substr(hash('sha256', $secret), 0, 16);
            try {
                $this->loginThrottler->assertNotLocked($throttleTarget, $clientIp);
            } catch (AccessDenied $e) {
                throw new CustomUserMessageAuthenticationException($e->getMessage());
            }

            $token = $this->authenticateAccessToken->findToken($secret);
            if (null === $token) {
                $this->loginThrottler->recordFailure($throttleTarget, $clientIp);
                throw new BadCredentialsException('Invalid access token.');
            }

            $this->assertTokenAllowed($token, $repo, $request);

            $user = $token->user();
            $this->loginThrottler->recordSuccess($throttleTarget, $clientIp);

            return new SelfValidatingPassport(new UserBadge($user->username(), static fn() => new SecurityUser($user)));
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
            $clientIp = $request->getClientIp() ?? '127.0.0.1';
            try {
                $this->loginThrottler->assertNotLocked($username, $clientIp);
            } catch (AccessDenied $e) {
                throw new CustomUserMessageAuthenticationException($e->getMessage());
            }

            $user = $this->userStore->byUsername($username);
            if (null !== $user) {
                if ($this->passwordHasher->isPasswordValid(new SecurityUser($user), $password)) {
                    $this->loginThrottler->recordSuccess($username, $clientIp);

                    return new SelfValidatingPassport(new UserBadge($user->username(), static fn() => new SecurityUser($user)));
                }

                // Check if password is an access token for this or another user
                $token = $this->authenticateAccessToken->findToken($password);
                if (null !== $token) {
                    $this->assertTokenAllowed($token, $repo, $request);
                    $tokenUser = $token->user();

                    $this->loginThrottler->recordSuccess($username, $clientIp);

                    return new SelfValidatingPassport(new UserBadge($tokenUser->username(), static fn() => new SecurityUser($tokenUser)));
                }

                $this->loginThrottler->recordFailure($username, $clientIp);

                throw new BadCredentialsException('Invalid password or token.');
            }

            // Username not found, check if password is an access token
            $token = $this->authenticateAccessToken->findToken($password);
            if (null !== $token) {
                $this->assertTokenAllowed($token, $repo, $request);
                $tokenUser = $token->user();

                $this->loginThrottler->recordSuccess($username, $clientIp);

                return new SelfValidatingPassport(new UserBadge($tokenUser->username(), static fn() => new SecurityUser($tokenUser)));
            }

            $this->loginThrottler->recordFailure($username, $clientIp);

            throw new BadCredentialsException('User not found and invalid token.');
        }

        if ('' !== $username) {
            // Try username as an access token
            $token = $this->authenticateAccessToken->findToken($username);
            if (null !== $token) {
                $this->assertTokenAllowed($token, $repo, $request);
                $tokenUser = $token->user();

                return new SelfValidatingPassport(new UserBadge($tokenUser->username(), static fn() => new SecurityUser($tokenUser)));
            }

            throw new BadCredentialsException('Invalid access token.');
        }

        throw new BadCredentialsException('Missing credentials.');
    }

    private function extractRepoName(Request $request): string
    {
        $attr = $request->attributes->get('repo');
        if (\is_string($attr) && '' !== $attr) {
            return preg_replace('/\.git$/i', '', trim($attr, '/')) ?? $attr;
        }

        $path = $request->getPathInfo();
        if (preg_match('#^/(.+?)(?:\.git)?(?:/info/lfs(?:/.*)?|/info/refs|/git-(?:upload|receive)-pack)$#', $path, $m)) {
            return $m[1];
        }
        if (preg_match('#^/([^/]+?)(?:\.git)?(?:/.*)?$#', $path, $m)) {
            return $m[1];
        }

        return '';
    }

    private function assertTokenAllowed(AccessToken $token, string $repo, Request $request): void
    {
        if ('' !== $repo && !$token->allowsRepo($repo)) {
            throw new BadCredentialsException(sprintf('Access token is not authorized for repository "%s".', $repo));
        }

        $path = $request->getPathInfo();
        $isLfs = str_contains($path, '/info/lfs') || 'application/vnd.git-lfs+json' === $request->headers->get('Accept');
        if ($isLfs) {
            $method = strtoupper($request->getMethod());
            $isWriteLfs = \in_array($method, ['PUT', 'DELETE'], true)
                || ('POST' === $method && (str_contains($path, '/verify') || str_contains($path, '/locks')));
            if (!$isWriteLfs && 'POST' === $method && str_contains($path, '/objects/batch')) {
                $decoded = json_decode($request->getContent(), true);
                if (\is_array($decoded) && 'upload' === ($decoded['operation'] ?? null)) {
                    $isWriteLfs = true;
                }
            }
            if (!$token->hasScope($isWriteLfs ? 'lfs:upload' : 'lfs:download')) {
                throw new BadCredentialsException('Access token missing required scope for Git LFS.');
            }

            return;
        }

        if ($token->isEphemeralLfsOnly()) {
            throw new BadCredentialsException('Git LFS ephemeral token cannot be used outside Git LFS endpoints.');
        }

        $isPush = str_contains($path, 'git-receive-pack') || 'git-receive-pack' === $request->query->get('service');
        if ($isPush && !$token->hasScope('repo:write')) {
            throw new BadCredentialsException('Access token missing required write scope.');
        }
        if (!$isPush && !$token->hasScope('repo:read')) {
            throw new BadCredentialsException('Access token missing required read scope.');
        }
    }

    /**
     * Authenticate the `codes_session` cookie minted by the `web login` flow,
     * rejecting it when the user's web_token_version has since been bumped
     * (i.e. after `web logout`).
     */
    private function authenticateSessionCookie(Request $request): Passport
    {
        $cookie = $request->cookies->get(WebSessionTokens::COOKIE_NAME) ?? $request->cookies->get('codes_session');
        $session = \is_string($cookie) && '' !== $cookie ? $this->webSessionTokens->verifySession($cookie) : null;

        if (null !== $session) {
            $user = $this->userStore->byId($session['id']);
            if (null !== $user && $user->webTokenVersion() === $session['version']) {
                return new SelfValidatingPassport(new UserBadge($user->username(), static fn() => new SecurityUser($user)));
            }
        }

        throw new BadCredentialsException('Invalid or revoked session cookie.');
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        if ($exception instanceof CustomUserMessageAuthenticationException) {
            return new Response($exception->getMessage(), Response::HTTP_TOO_MANY_REQUESTS, [
                'Content-Type' => 'text/plain',
                'Retry-After' => '900',
            ]);
        }

        // Mark bad credentials so authorizer/denyRead can answer 403 or 401 accordingly
        $request->attributes->set('_bad_credentials', true);

        return null;
    }
}

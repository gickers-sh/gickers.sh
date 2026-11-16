<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Identity\Domain\UserStore;
use App\Identity\Infrastructure\WebAuth\WebSessionTokens;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Redeems the magic link printed by `web login`: validates the single-purpose
 * login ticket, issues the long-lived `codes_session` cookie, and redirects to
 * the "My Repositories" dashboard. The browser is now authenticated for every
 * page view without ever changing a repository URL.
 */
final readonly class WebLoginController
{
    public function __construct(
        private WebSessionTokens $tokens,
        private UserStore $users,
    ) {}

    #[Route('/login/{token}', methods: ['GET'], requirements: ['token' => '[^/]+'])]
    public function __invoke(Request $request, string $token): Response
    {
        $userId = $this->tokens->verifyLoginTicket($token);
        $user = null === $userId ? null : $this->users->byId($userId);

        if (null === $user) {
            // Expired, tampered, or already-rotated ticket. Keep it generic.
            return new Response(
                "This sign-in link is invalid or has expired. Run `web login` again.\n",
                Response::HTTP_UNAUTHORIZED,
                ['Content-Type' => 'text/plain; charset=utf-8', 'Referrer-Policy' => 'no-referrer'],
            );
        }

        $cookie = Cookie::create(WebSessionTokens::COOKIE_NAME)
            ->withValue($this->tokens->issueSession($user))
            ->withPath('/')
            ->withExpires(time() + $this->tokens->sessionTtlSeconds())
            ->withSecure($request->isSecure())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);

        // 303 to the user's repositories dashboard drops the one-time ticket from the address bar;
        // no-referrer stops it leaking onward.
        $response = new RedirectResponse('/@' . $user->username(), Response::HTTP_SEE_OTHER, ['Referrer-Policy' => 'no-referrer']);
        $response->headers->setCookie($cookie);

        return $response;
    }
}

<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Identity\Infrastructure\WebAuth\WebSessionTokens;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Handles web logout via click: invalidates the user's web session on the server
 * (bumping web_token_version, identical to `web logout`), clears the
 * `codes_session` cookie, and redirects to the home page.
 */
final readonly class WebLogoutController
{
    public function __construct(
        private Security $security,
        private UserStore $users,
        private WebSessionTokens $tokens,
        private ClockInterface $clock,
    ) {
    }

    #[Route('/logout', name: 'app_logout', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $user = $this->domainUser($request);
        if (null !== $user) {
            $user->revokeWebSessions($this->clock->now());
            $this->users->save($user);
        }

        $response = new RedirectResponse('/', Response::HTTP_SEE_OTHER);
        $response->headers->clearCookie(
            name: WebSessionTokens::COOKIE_NAME,
            path: '/',
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: Cookie::SAMESITE_LAX,
        );

        return $response;
    }

    private function domainUser(Request $request): ?User
    {
        $securityUser = $this->security->getUser();
        if ($securityUser instanceof SecurityUser) {
            return $securityUser->user();
        }

        $cookie = $request->cookies->get(WebSessionTokens::COOKIE_NAME);
        if (\is_string($cookie) && '' !== $cookie) {
            $session = $this->tokens->verifySession($cookie);
            if (null !== $session) {
                return $this->users->byId($session['id']);
            }
        }

        return null;
    }
}

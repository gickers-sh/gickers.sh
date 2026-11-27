<?php

declare(strict_types=1);

namespace App\Tests\Functional\UI\Http;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Identity\Infrastructure\WebAuth\WebSessionTokens;
use App\Repo\Application\CreateRepo;
use App\Repo\Domain\RepoName;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Tests\GitTestHelper;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `web login` browser flow: an SSH-minted magic link sets a `gickers_session`
 * cookie, after which repository pages detect the user (without any URL change)
 * and reveal the admin command palette. `web logout` (version bump) revokes it.
 */
final class WebLoginFlowTest extends KernelTestCase
{
    private WebSessionTokens $tokens;
    private UserStore $users;
    private CreateRepo $createRepo;
    private BareRepositoryStorage $storage;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->tokens = $c->get(WebSessionTokens::class);
        $this->users = $c->get(UserStore::class);
        $this->createRepo = $c->get(CreateRepo::class);
        $this->storage = $c->get(BareRepositoryStorage::class);
    }

    public function testMagicLinkAuthenticatesBrowserAndExposesAdminPalette(): void
    {
        $owner = new User('weblogin-'.bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $this->users->save($owner);

        $repo = ($this->createRepo)($owner, $owner->username().'/wl-'.bin2hex(random_bytes(4)), private: true);
        $name = $repo->name()->value;
        GitTestHelper::writeFile($this->storage->open(RepoName::fromInput($name)), 'main', 'README.md', "# Secret\n", 'readme');

        // Private repo is invisible to an anonymous browser.
        $this->assertSame(404, $this->get('/'.$name)->getStatusCode());

        // Redeem the SSH-minted magic link; it must set the session cookie and
        // redirect to the user's repository profile "/@{username}".
        $ticket = $this->tokens->issueLoginTicket($owner);
        $redeem = $this->get('/login/'.$ticket);
        $this->assertSame(Response::HTTP_SEE_OTHER, $redeem->getStatusCode());
        $this->assertSame('/@'.$owner->username(), $redeem->headers->get('Location'));
        $cookie = $this->sessionCookie($redeem);
        $this->assertNotNull($cookie, 'login must set a gickers_session cookie');

        // The dashboard at "/@{username}" lists the owned repository with its role.
        $dashboard = $this->get('/@'.$owner->username(), $cookie);
        $this->assertSame(200, $dashboard->getStatusCode());
        $dashHtml = (string) $dashboard->getContent();
        $this->assertStringContainsString('My Repositories', $dashHtml);
        $this->assertStringContainsString('href="/'.$name.'"', $dashHtml);
        $this->assertStringContainsString('Owner', $dashHtml);

        // The home page at "/" is a clean landing page linking to the user's dashboard.
        $home = $this->get('/', $cookie);
        $this->assertSame(200, $home->getStatusCode());
        $homeHtml = (string) $home->getContent();
        $this->assertStringContainsString('Signed in as', $homeHtml);
        $this->assertStringContainsString('@'.$owner->username(), $homeHtml);
        $this->assertStringContainsString('href="/@'.$owner->username().'"', $homeHtml);

        // With the cookie, the owner sees the page AND the admin-tier commands.
        $page = $this->get('/'.$name, $cookie);
        $this->assertSame(200, $page->getStatusCode());
        $html = (string) $page->getContent();
        $this->assertStringContainsString('repo webhook create '.$name, $html);
        $this->assertStringContainsString('repo collab add '.$name, $html);
        $this->assertStringContainsString('data-filter="admin"', $html);

        // After `web logout` bumps the version, the old cookie is rejected: the
        // private repo is hidden again.
        $reloadedOwner = $this->users->byId($owner->id()) ?? throw new \LogicException();
        $reloadedOwner->revokeWebSessions(new \DateTimeImmutable());
        $this->users->save($reloadedOwner);
        $this->assertSame(404, $this->get('/'.$name, $cookie)->getStatusCode());
    }

    public function testMagicLinkCannotBeReused(): void
    {
        $user = new User('singleuse-'.bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $this->users->save($user);

        $ticket = $this->tokens->issueLoginTicket($user);

        // 1. First redemption succeeds
        $firstRedeem = $this->get('/login/'.$ticket);
        $this->assertSame(Response::HTTP_SEE_OTHER, $firstRedeem->getStatusCode());
        $this->assertNotNull($this->sessionCookie($firstRedeem));

        // 2. Second redemption MUST be rejected (strict single-use)
        $secondRedeem = $this->get('/login/'.$ticket);
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $secondRedeem->getStatusCode());
        $this->assertStringContainsString('This sign-in link is invalid or has expired.', (string) $secondRedeem->getContent());
    }

    public function testAnonymousPublicRepoHidesAdminCommands(): void
    {
        $owner = new User('wlpub-'.bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $this->users->save($owner);
        $repo = ($this->createRepo)($owner, $owner->username().'/wlp-'.bin2hex(random_bytes(4)), private: false);
        $name = $repo->name()->value;
        GitTestHelper::writeFile($this->storage->open(RepoName::fromInput($name)), 'main', 'README.md', "# Pub\n", 'readme');

        $html = (string) $this->get('/'.$name)->getContent();
        $this->assertStringContainsString('repo info '.$name, $html);
        $this->assertStringNotContainsString('repo webhook create', $html);
        $this->assertStringNotContainsString('data-filter="admin"', $html);
    }

    public function testAnonymousDashboardRendersLoginCommandAndCopyButton(): void
    {
        $response = $this->get('/');
        $this->assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();

        $this->assertStringContainsString('You are not signed in to this browser.', $html);
        $this->assertMatchesRegularExpression('#<code>[^<]*web login</code>#', $html);
        $this->assertStringNotContainsString('cli-login-link', $html);
        $this->assertStringNotContainsString('href="ssh://', $html);
        $this->assertStringContainsString('data-copy="', $html);
        $this->assertStringContainsString('<script src="/js/repo-web.js"', $html);
    }

    public function testAnonymousProfileOnlyShowsPublicRepos(): void
    {
        $owner = new User('profileuser-'.bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $this->users->save($owner);

        $pubRepo = ($this->createRepo)($owner, $owner->username().'/pub-'.bin2hex(random_bytes(4)), private: false);
        $privRepo = ($this->createRepo)($owner, $owner->username().'/priv-'.bin2hex(random_bytes(4)), private: true);

        $response = $this->get('/@'.$owner->username());
        $this->assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();

        $this->assertStringContainsString('@'.$owner->username(), $html);
        $this->assertStringContainsString('href="/'.$pubRepo->name()->value.'"', $html);
        $this->assertStringNotContainsString('href="/'.$privRepo->name()->value.'"', $html);
    }

    public function testNonexistentUserReturns404(): void
    {
        $response = $this->get('/@nonexistent-user-xyz');
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('User not found.', (string) $response->getContent());
    }

    public function testUsernameWithoutAtSignRedirectsToProfile(): void
    {
        $owner = new User('shortcut-'.bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $this->users->save($owner);

        $response = $this->get('/'.$owner->username());
        $this->assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        $this->assertSame('/@'.$owner->username(), $response->headers->get('Location'));
    }

    public function testMyRepositoriesAliasRedirectsWhenAuthenticated(): void
    {
        $owner = new User('myrepos-'.bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $this->users->save($owner);

        // Anonymous reaches guidance
        $anon = $this->get('/my-repositories');
        $this->assertSame(200, $anon->getStatusCode());
        $this->assertStringContainsString('You are not signed in to this browser.', (string) $anon->getContent());

        // Authenticated redirects to /@{username}
        $ticket = $this->tokens->issueLoginTicket($owner);
        $redeem = $this->get('/login/'.$ticket);
        $cookie = $this->sessionCookie($redeem);

        $auth = $this->get('/my-repositories', $cookie);
        $this->assertSame(Response::HTTP_SEE_OTHER, $auth->getStatusCode());
        $this->assertSame('/@'.$owner->username(), $auth->headers->get('Location'));
    }

    public function testWebLogoutViaClickRevokesSessionAndClearsCookie(): void
    {
        $owner = new User('logoutuser-'.bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $this->users->save($owner);
        $initialVersion = $owner->webTokenVersion();

        $repo = ($this->createRepo)($owner, $owner->username().'/logout-priv-'.bin2hex(random_bytes(4)), private: true);
        $name = $repo->name()->value;
        GitTestHelper::writeFile($this->storage->open(RepoName::fromInput($name)), 'main', 'README.md', "# Private\n", 'readme');

        $ticket = $this->tokens->issueLoginTicket($owner);
        $redeem = $this->get('/login/'.$ticket);
        $cookie = $this->sessionCookie($redeem);
        $this->assertNotNull($cookie);

        // Verify "Log out" link is rendered in web view for authenticated user
        $home = $this->get('/', $cookie);
        $this->assertStringContainsString('href="/logout"', (string) $home->getContent());

        $dashboard = $this->get('/@'.$owner->username(), $cookie);
        $this->assertStringContainsString('href="/logout"', (string) $dashboard->getContent());

        $docs = $this->get('/docs', $cookie);
        $this->assertStringContainsString('href="/logout"', (string) $docs->getContent());

        $repoView = $this->get('/'.$name, $cookie);
        $this->assertStringContainsString('href="/logout"', (string) $repoView->getContent());

        // Perform logout via click (GET /logout)
        $logout = $this->get('/logout', $cookie);
        $this->assertSame(Response::HTTP_SEE_OTHER, $logout->getStatusCode());
        $this->assertSame('/', $logout->headers->get('Location'));

        // Cookie is cleared
        $cookies = $logout->headers->getCookies();
        $clearedCookie = null;
        foreach ($cookies as $c) {
            if (WebSessionTokens::COOKIE_NAME === $c->getName()) {
                $clearedCookie = $c;
                break;
            }
        }
        $this->assertNotNull($clearedCookie, 'Logout must send cookie clearing header');
        $this->assertTrue($clearedCookie->getExpiresTime() <= time() || null === $clearedCookie->getValue() || '' === $clearedCookie->getValue());

        // Database token version was bumped
        $reloaded = $this->users->byId($owner->id()) ?? throw new \LogicException();
        $this->assertSame($initialVersion + 1, $reloaded->webTokenVersion());

        // The previous cookie can no longer view private repos
        $this->assertSame(404, $this->get('/'.$name, $cookie)->getStatusCode());
    }

    public function testAnonymousLogoutRedirectsGracefully(): void
    {
        $response = $this->get('/logout');
        $this->assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        $this->assertSame('/', $response->headers->get('Location'));
    }

    private function sessionCookie(Response $response): ?string
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if (WebSessionTokens::COOKIE_NAME === $cookie->getName()) {
                return $cookie->getValue();
            }
        }

        return null;
    }

    private function get(string $uri, ?string $sessionCookie = null): Response
    {
        $cookies = null === $sessionCookie ? [] : [WebSessionTokens::COOKIE_NAME => $sessionCookie];
        $request = Request::create($uri, 'GET', [], $cookies);

        $kernel = self::$kernel ?? throw new \LogicException('Kernel not booted');
        $response = $kernel->handle($request);
        if ($kernel instanceof \Symfony\Component\HttpKernel\TerminableInterface) {
            $kernel->terminate($request, $response);
        }

        return $response;
    }
}

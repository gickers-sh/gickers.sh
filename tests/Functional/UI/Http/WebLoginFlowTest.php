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
 * The `web login` browser flow: an SSH-minted magic link sets a `codes_session`
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
        $owner = new User('weblogin-' . bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $this->users->save($owner);

        $repo = ($this->createRepo)($owner, 'wl-' . bin2hex(random_bytes(4)), private: true);
        $name = $repo->name()->value;
        GitTestHelper::writeFile($this->storage->open(RepoName::fromInput($name)), 'main', 'README.md', "# Secret\n", 'readme');

        // Private repo is invisible to an anonymous browser.
        $this->assertSame(404, $this->get('/' . $name)->getStatusCode());

        // Redeem the SSH-minted magic link; it must set the session cookie and
        // redirect to the "My Repositories" dashboard.
        $ticket = $this->tokens->issueLoginTicket($owner);
        $redeem = $this->get('/login/' . $ticket);
        $this->assertSame(Response::HTTP_SEE_OTHER, $redeem->getStatusCode());
        $this->assertSame('/', $redeem->headers->get('Location'));
        $cookie = $this->sessionCookie($redeem);
        $this->assertNotNull($cookie, 'login must set a codes_session cookie');

        // The dashboard at "/" lists the owned repository with its role.
        $dashboard = $this->get('/', $cookie);
        $this->assertSame(200, $dashboard->getStatusCode());
        $dashHtml = (string) $dashboard->getContent();
        $this->assertStringContainsString('My Repositories', $dashHtml);
        $this->assertStringContainsString('href="/' . $name . '"', $dashHtml);
        $this->assertStringContainsString('Owner', $dashHtml);

        // With the cookie, the owner sees the page AND the admin-tier commands.
        $page = $this->get('/' . $name, $cookie);
        $this->assertSame(200, $page->getStatusCode());
        $html = (string) $page->getContent();
        $this->assertStringContainsString('repo webhook create ' . $name, $html);
        $this->assertStringContainsString('repo collab add ' . $name, $html);
        $this->assertStringContainsString('data-filter="admin"', $html);

        // After `web logout` bumps the version, the old cookie is rejected: the
        // private repo is hidden again.
        $reloadedOwner = $this->users->byId($owner->id()) ?? throw new \LogicException();
        $reloadedOwner->revokeWebSessions(new \DateTimeImmutable());
        $this->users->save($reloadedOwner);
        $this->assertSame(404, $this->get('/' . $name, $cookie)->getStatusCode());
    }

    public function testAnonymousPublicRepoHidesAdminCommands(): void
    {
        $owner = new User('wlpub-' . bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $this->users->save($owner);
        $repo = ($this->createRepo)($owner, 'wlp-' . bin2hex(random_bytes(4)), private: false);
        $name = $repo->name()->value;
        GitTestHelper::writeFile($this->storage->open(RepoName::fromInput($name)), 'main', 'README.md', "# Pub\n", 'readme');

        $html = (string) $this->get('/' . $name)->getContent();
        $this->assertStringContainsString('repo info ' . $name, $html);
        $this->assertStringNotContainsString('repo webhook create', $html);
        $this->assertStringNotContainsString('data-filter="admin"', $html);
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

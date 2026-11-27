<?php

declare(strict_types=1);

namespace App\Tests\Functional\UI\Http;

use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Identity\Infrastructure\WebAuth\WebSessionTokens;
use App\Repo\Application\AddCollaborator;
use App\Repo\Application\CreateRepo;
use App\Repo\Domain\RepoName;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Tests\GitTestHelper;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class HomeAndProfilePageTest extends KernelTestCase
{
    private WebSessionTokens $tokens;
    private UserStore $users;
    private CreateRepo $createRepo;
    private AddCollaborator $addCollab;
    private BareRepositoryStorage $storage;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->tokens = $c->get(WebSessionTokens::class);
        $this->users = $c->get(UserStore::class);
        $this->createRepo = $c->get(CreateRepo::class);
        $this->addCollab = $c->get(AddCollaborator::class);
        $this->storage = $c->get(BareRepositoryStorage::class);
    }

    public function testAnonymousHomePageRendersLandingWithOnboardingAndGateways(): void
    {
        $owner = new User('author-' . bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $this->users->save($owner);

        $pub = ($this->createRepo)($owner, $owner->username() . '/pub-' . bin2hex(random_bytes(4)), private: false, description: 'A public demo');
        $priv = ($this->createRepo)($owner, $owner->username() . '/priv-' . bin2hex(random_bytes(4)), private: true, description: 'A private demo');

        $response = $this->get('/');
        $this->assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();

        $this->assertStringContainsString('Quick Start &middot; Onboarding', $html);
        $this->assertStringContainsString('signup', $html);
        $this->assertStringContainsString('Tip:', $html);
        $this->assertStringContainsString('data-copy="ssh -p 2223 localhost signup"', $html);
        $this->assertStringContainsString('data-copy="ssh -p 2223 localhost"', $html);
        $this->assertStringNotContainsString('data-copy="ssh -p 2223 &lt;username&gt;', $html);
        $this->assertStringContainsString('&lt;username&gt;', $html);
        $this->assertStringContainsString('Terminal login', $html);
        $this->assertStringContainsString('Public Repositories', $html);
        $this->assertStringContainsString('href="/repos"', $html);
        $this->assertStringContainsString('href="/docs"', $html);
        $this->assertStringContainsString('1 public repository', $html);
        $this->assertStringNotContainsString('href="/' . $priv->name()->value . '"', $html);
    }

    public function testPublicRepositoriesPageListsPublicReposAndHidesPrivate(): void
    {
        $owner = new User('author-' . bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $this->users->save($owner);

        $pub = ($this->createRepo)($owner, $owner->username() . '/pub-' . bin2hex(random_bytes(4)), private: false, description: 'A public demo');
        $priv = ($this->createRepo)($owner, $owner->username() . '/priv-' . bin2hex(random_bytes(4)), private: true, description: 'A private demo');

        foreach (['/repos', '/repositories'] as $uri) {
            $response = $this->get($uri);
            $this->assertSame(200, $response->getStatusCode(), "Failed asserting 200 for {$uri}");
            $html = (string) $response->getContent();

            $this->assertStringContainsString('Public Repositories', $html);
            $this->assertStringContainsString('href="/' . $pub->name()->value . '"', $html);
            $this->assertStringContainsString('A public demo', $html);
            $this->assertStringContainsString('@' . $owner->username(), $html);
            $this->assertStringNotContainsString('href="/' . $priv->name()->value . '"', $html);
        }
    }

    public function testAuthenticatedHomePageShowsUserPillAndProfileLink(): void
    {
        $user = new User('logged-' . bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $this->users->save($user);

        $cookie = $this->tokens->issueSession($user);
        $response = $this->get('/', $cookie);
        $this->assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();

        $this->assertStringContainsString('Signed in as', $html);
        $this->assertStringContainsString('@' . $user->username(), $html);
        $this->assertStringContainsString('href="/@' . $user->username() . '"', $html);
        $this->assertStringContainsString('My Repositories &rarr;', $html);
    }

    public function testOwnerVisitingOwnProfileSeesAllReposAndRoles(): void
    {
        $owner = new User('owner-' . bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $this->users->save($owner);

        $other = new User('other-' . bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $this->users->save($other);

        $myPub = ($this->createRepo)($owner, $owner->username() . '/mypub-' . bin2hex(random_bytes(4)), private: false);
        $myPriv = ($this->createRepo)($owner, $owner->username() . '/mypriv-' . bin2hex(random_bytes(4)), private: true);

        // A repo owned by $other where $owner is collaborator
        $collabRepo = ($this->createRepo)($other, $other->username() . '/collab-' . bin2hex(random_bytes(4)), private: true);
        ($this->addCollab)($other, $collabRepo->name(), $owner->username(), AccessLevel::ReadWriteAccess);

        $cookie = $this->tokens->issueSession($owner);
        $response = $this->get('/@' . $owner->username(), $cookie);
        $this->assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();

        $this->assertStringContainsString('My Repositories', $html);
        $this->assertStringContainsString('@' . $owner->username(), $html);
        $this->assertStringContainsString('href="/' . $myPub->name()->value . '"', $html);
        $this->assertStringContainsString('href="/' . $myPriv->name()->value . '"', $html);
        $this->assertStringContainsString('href="/' . $collabRepo->name()->value . '"', $html);
        $this->assertStringContainsString('Owner', $html);
        $this->assertStringContainsString('Write', $html);
    }

    public function testAnonymousVisitorOnlySeesPublicReposOnProfile(): void
    {
        $user = new User('artist-' . bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $this->users->save($user);

        $pub = ($this->createRepo)($user, $user->username() . '/paint-' . bin2hex(random_bytes(4)), private: false);
        $priv = ($this->createRepo)($user, $user->username() . '/secret-art-' . bin2hex(random_bytes(4)), private: true);

        $response = $this->get('/@' . $user->username());
        $this->assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();

        $this->assertStringContainsString('@' . $user->username(), $html);
        $this->assertStringContainsString('href="/' . $pub->name()->value . '"', $html);
        $this->assertStringNotContainsString('href="/' . $priv->name()->value . '"', $html);
    }

    public function testNonexistentProfileReturns404(): void
    {
        $response = $this->get('/@does-not-exist');
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('User not found.', (string) $response->getContent());
    }

    public function testUsernameWithoutAtSignRedirectsToAtUsername(): void
    {
        $user = new User('direct-' . bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $this->users->save($user);

        $response = $this->get('/' . $user->username());
        $this->assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        $this->assertSame('/@' . $user->username(), $response->headers->get('Location'));
    }

    public function testExistingRepoWinsOverUsernameShortcut(): void
    {
        $user = new User('clash-' . bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $this->users->save($user);
        $admin = new User('clashadmin-' . bin2hex(random_bytes(3)), true, new \DateTimeImmutable());
        $this->users->save($admin);

        // Admin creates a root-level repository with the exact same name as the user
        $repo = ($this->createRepo)($admin, $user->username(), private: false);
        GitTestHelper::writeFile($this->storage->open(RepoName::fromInput($user->username())), 'main', 'README.md', "# Clash\n", 'readme');

        $response = $this->get('/' . $user->username());
        // Since the repo exists, it serves the repo page (HTTP 200), not a redirect to /@username!
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<h1>' . $user->username() . '</h1>', (string) $response->getContent());

        // But visiting /@username explicitly still serves the user's profile
        $profile = $this->get('/@' . $user->username());
        $this->assertSame(200, $profile->getStatusCode());
        $this->assertStringContainsString('@' . $user->username(), (string) $profile->getContent());
    }

    public function testMyRepositoriesRouteRedirectsAuthenticatedUser(): void
    {
        $user = new User('redirected-' . bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $this->users->save($user);

        $cookie = $this->tokens->issueSession($user);
        $response = $this->get('/my-repositories', $cookie);
        $this->assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        $this->assertSame('/@' . $user->username(), $response->headers->get('Location'));
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

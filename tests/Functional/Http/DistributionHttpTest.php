<?php

declare(strict_types=1);

namespace App\Tests\Functional\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\IssueAccessToken;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Application\CreateRepo;
use App\Repo\Domain\Git\GitRepository;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Tests\GitTestHelper;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for the distribution HTTP surface: raw blob serving,
 * including access control and 404 handling.
 */
final class DistributionHttpTest extends KernelTestCase
{
    private CreateRepo $createRepo;
    private CreateUser $createUser;
    private IssueAccessToken $issueToken;
    private BareRepositoryStorage $storage;
    private User $admin;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();

        $this->createRepo = $c->get(CreateRepo::class);
        $this->createUser = $c->get(CreateUser::class);
        $this->issueToken = $c->get(IssueAccessToken::class);
        $this->storage = $c->get(BareRepositoryStorage::class);

        $admin = new User('disthttp-admin-'.bin2hex(random_bytes(3)), true, new \DateTimeImmutable());
        $c->get(UserStore::class)->save($admin);
        $this->admin = $admin;
    }

    private function user(string $prefix): User
    {
        return ($this->createUser)($this->admin, $prefix.'-'.bin2hex(random_bytes(3)), false);
    }

    private function secret(User $user): string
    {
        [, $secret] = ($this->issueToken)($user, 'tok');

        return $secret;
    }

    private function handle(Request $request): Response
    {
        $kernel = self::$kernel ?? throw new \LogicException('Kernel not booted');
        $response = $kernel->handle($request);
        if ($kernel instanceof \Symfony\Component\HttpKernel\TerminableInterface) {
            $kernel->terminate($request, $response);
        }

        return $response;
    }

    /** @return array{0: string, 1: GitRepository} repo name and opened bare repo */
    private function repoWithContent(User $owner, bool $private): array
    {
        $repo = ($this->createRepo)($owner, 'dist-'.bin2hex(random_bytes(4)), private: $private);
        $git = $this->storage->open($repo->name());
        GitTestHelper::writeFile($git, 'main', 'README.md', "# Hello\nbody line\n", 'add readme');
        GitTestHelper::writeFile($git, 'main', 'assets/logo.bin', "\x00\x01\x02binary\xff", 'add bin');

        return [$repo->name()->value, $git];
    }

    public function testRawServesTextAndBinaryWithDetectedContentType(): void
    {
        $owner = $this->user('alice');
        [$name] = $this->repoWithContent($owner, private: false);

        $text = $this->handle(Request::create(sprintf('/%s/raw/main/README.md', $name), 'GET'));
        self::assertSame(200, $text->getStatusCode());
        self::assertStringContainsString('text/markdown', (string) $text->headers->get('Content-Type'));
        self::assertSame("# Hello\nbody line\n", $text->getContent());

        $bin = $this->handle(Request::create(sprintf('/%s/raw/main/assets/logo.bin', $name), 'GET'));
        self::assertSame(200, $bin->getStatusCode());
        self::assertSame('application/octet-stream', $bin->headers->get('Content-Type'));
        self::assertSame("\x00\x01\x02binary\xff", $bin->getContent());
    }

    public function testRawReturns404ForMissingRefPathAndTraversal(): void
    {
        $owner = $this->user('bob');
        [$name] = $this->repoWithContent($owner, private: false);

        self::assertSame(404, $this->handle(Request::create(sprintf('/%s/raw/main/nope.txt', $name), 'GET'))->getStatusCode());
        self::assertSame(404, $this->handle(Request::create(sprintf('/%s/raw/no-such-ref/README.md', $name), 'GET'))->getStatusCode());
        self::assertSame(404, $this->handle(Request::create(sprintf('/%s/raw/main/../../etc/passwd', $name), 'GET'))->getStatusCode());
    }

    public function testRawPrivateRepoRequiresRead(): void
    {
        $owner = $this->user('carol');
        [$name] = $this->repoWithContent($owner, private: true);

        $anon = $this->handle(Request::create(sprintf('/%s/raw/main/README.md', $name), 'GET'));
        self::assertSame(401, $anon->getStatusCode());

        $ok = $this->handle(Request::create(
            sprintf('/%s/raw/main/README.md', $name),
            'GET',
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Token '.$this->secret($owner)],
        ));
        self::assertSame(200, $ok->getStatusCode());
    }
}

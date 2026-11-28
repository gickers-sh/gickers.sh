<?php

declare(strict_types=1);

namespace App\Tests\Functional\UI\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\IssueAccessToken;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Application\CreateRepo;
use App\Repo\Domain\Git\GitRepository;
use App\Repo\Domain\RepoName;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\Tests\GitTestHelper;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

/**
 * `GET /{repo}` renders the README and clone command through the HTTP pipeline,
 * preserving access control, escaped content, and catch-all route precedence.
 */
final class RepoWebPageTest extends KernelTestCase
{
    private CreateUser $createUser;
    private CreateRepo $createRepo;
    private IssueAccessToken $issueToken;
    private BareRepositoryStorage $storage;
    private ServerSettings $settings;
    private User $admin;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var CreateUser $createUser */
        $createUser = $container->get(CreateUser::class);
        $this->createUser = $createUser;

        /** @var CreateRepo $createRepo */
        $createRepo = $container->get(CreateRepo::class);
        $this->createRepo = $createRepo;

        /** @var IssueAccessToken $issueToken */
        $issueToken = $container->get(IssueAccessToken::class);
        $this->issueToken = $issueToken;

        /** @var BareRepositoryStorage $storage */
        $storage = $container->get(BareRepositoryStorage::class);
        $this->storage = $storage;

        /** @var ServerSettings $settings */
        $settings = $container->get(ServerSettings::class);
        $this->settings = $settings;

        /** @var UserStore $userStore */
        $userStore = $container->get(UserStore::class);
        $admin = new User('webadmin-' . bin2hex(random_bytes(3)), true, new \DateTimeImmutable());
        $userStore->save($admin);
        $this->admin = $admin;
    }

    private function user(string $prefix): User
    {
        return ($this->createUser)($this->admin, $prefix . '-' . bin2hex(random_bytes(3)));
    }

    private function secret(User $user): string
    {
        [, $secret] = ($this->issueToken)($user, 'web-token');

        return $secret;
    }

    /**
     * Creates a repository and returns its name.
     */
    private function repo(User $owner, bool $private = false, string $description = ''): string
    {
        $repo = ($this->createRepo)($owner, $owner->username() . '/web-' . bin2hex(random_bytes(4)), private: $private, description: $description);

        return $repo->name()->value;
    }

    private function git(string $name): GitRepository
    {
        return $this->storage->open(RepoName::fromInput($name));
    }

    private function get(string $uri, ?string $token = null): Response
    {
        $server = null === $token ? [] : ['HTTP_AUTHORIZATION' => 'Token ' . $token];
        $request = Request::create($uri, 'GET', [], [], [], $server);

        $kernel = self::$kernel ?? throw new \LogicException('Kernel not booted');
        $response = $kernel->handle($request);
        if ($kernel instanceof \Symfony\Component\HttpKernel\TerminableInterface) {
            $kernel->terminate($request, $response);
        }

        return $response;
    }

    private function sshCloneCommand(string $name): string
    {
        return sprintf('git clone %s/%s.git', rtrim($this->settings->sshPublicUrl, '/'), $name);
    }

    /**
     * The discovery tag GoGetController emits for a repository (`<host[:port]>/<name> git <http url>/<name>.git`).
     */
    private function goImportMeta(string $name): string
    {
        $parsed = parse_url($this->settings->httpPublicUrl);
        $host = ($parsed['host'] ?? 'localhost') . (isset($parsed['port']) ? ':' . $parsed['port'] : '');

        return sprintf('<meta name="go-import" content="%s/%s git %s/%s.git">', $host, $name, rtrim($this->settings->httpPublicUrl, '/'), $name);
    }

    private function assertNotFound(Response $response): void
    {
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        $this->assertSame("Not Found\n", $response->getContent());
    }

    public function testPublicRepositoryShowsReadmeAndCloneCommand(): void
    {
        $name = $this->repo($this->user('alice'), description: 'A demo project');
        $git = $this->git($name);
        GitTestHelper::writeFile($git, 'main', 'README.md', "# Demo\n\nHello world\n", 'add readme');
        GitTestHelper::writeFile($git, 'main', 'src/app.php', "<?php\n", 'add source');
        GitTestHelper::writeFile($git, 'main', 'LICENSE', "MIT\n", 'add license');

        $response = $this->get('/' . $name);
        $html = (string) $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('<h1>' . $name . '</h1>', $html);
        $this->assertStringContainsString('A demo project', $html);
        $this->assertStringContainsString('<h1>Demo</h1>', $html);
        $this->assertStringContainsString('<p>Hello world</p>', $html);
        $this->assertStringContainsString($this->sshCloneCommand($name), $html);

        // The page presents repository metadata, CLI palette and README tabs, not the Git file tree.
        $this->assertStringContainsString('<nav class="tabs" aria-label="Repository sections"><a class="active" href="#commands" data-tab="commands">Commands</a><a href="#readme" data-tab="readme">Readme</a></nav>', $html);
        $this->assertStringContainsString('<footer class="status"><span class="status-name">' . $name . '</span>', $html);
        $this->assertStringNotContainsString('<ul', $html);
        $this->assertStringNotContainsString('src/app.php', $html);
        $this->assertStringNotContainsString('LICENSE</a>', $html);

        // The advertised file link serves the blob through the raw route.
        $raw = $this->get('/' . $name . '/raw/HEAD/LICENSE');
        $this->assertSame(200, $raw->getStatusCode());
        $this->assertSame("MIT\n", $raw->getContent());
    }

    public function testRepositoryContentIsHtmlEscaped(): void
    {
        $name = $this->repo($this->user('bob'), description: '<b>bold</b> "quoted"');
        $git = $this->git($name);
        GitTestHelper::writeFile($git, 'main', 'README.md', "<script>alert(\"x\")</script> & <img src=x onerror=alert(1)>\n\n[bad](javascript:alert(1))\n", 'add readme');
        GitTestHelper::writeFile($git, 'main', 'x<y>&z.txt', "data\n", 'add odd file name');

        $response = $this->get('/' . $name);
        $html = (string) $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('<a>bad</a>', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt; &quot;quoted&quot;', $html);

        // Percent-encoding in the link round-trips to the odd file name.
        $raw = $this->get('/' . $name . '/raw/HEAD/x%3Cy%3E%26z.txt');
        $this->assertSame(200, $raw->getStatusCode());
        $this->assertSame("data\n", $raw->getContent());
    }

    public function testReadmeRendersGitHubFlavoredMarkdown(): void
    {
        $name = $this->repo($this->user('gfm'));
        $markdown = "# GFM\n\n~~removed~~ **bold** and `inline code`.\n\n| Feature | Status |\n| --- | --- |\n| Tables | supported |\n";
        GitTestHelper::writeFile($this->git($name), 'main', 'README.md', $markdown, 'add GFM readme');

        $response = $this->get('/' . $name);
        $html = (string) $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<h1>GFM</h1>', $html);
        $this->assertStringContainsString('<del>removed</del>', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<code>inline code</code>', $html);
        $this->assertStringContainsString('<table>', $html);
    }

    public function testPageUsesSmallLocalAssetsAndEnforcesLocalCsp(): void
    {
        $name = $this->repo($this->user('carol'));
        GitTestHelper::writeFile($this->git($name), 'main', 'README.md', "# Hello\n", 'add readme');

        $response = $this->get('/' . $name);
        $html = (string) $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<link rel="stylesheet" href="/css/repo-web.css">', $html);
        $this->assertStringContainsString('<script src="/js/repo-web.js"', $html);
        // External assets (http/https/CDN) are forbidden
        $this->assertDoesNotMatchRegularExpression('#\ssrc=["\']https?://#i', $html);
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self'", $csp);

        $stylesheet = file_get_contents(dirname(__DIR__, 4) . '/public/css/repo-web.css');
        $this->assertIsString($stylesheet);
        $this->assertLessThan(40000, strlen($stylesheet));

        $script = file_get_contents(dirname(__DIR__, 4) . '/public/js/repo-web.js');
        $this->assertIsString($script);
        $this->assertLessThan(20000, strlen($script));
    }

    public function testPageRendersSshCliPaletteAndCopyButtons(): void
    {
        $name = $this->repo($this->user('palette'));
        GitTestHelper::writeFile($this->git($name), 'main', 'README.md', "# CLI\n```bash\necho hello\n```\n", 'add readme');

        $response = $this->get('/' . $name);
        $html = (string) $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('SSH CLI Palette', $html);
        $this->assertStringContainsString('repo tree', $html);
        $this->assertStringContainsString('repo info', $html);
        $this->assertStringContainsString('repo blob', $html);
        $this->assertStringContainsString('repo grep', $html);
        $this->assertStringContainsString('repo commit', $html);
        $this->assertStringContainsString('repo diff', $html);
        $this->assertStringContainsString('copy-btn', $html);
        // Expandable cards expose editable arguments/options to copy the exact CLI command.
        $this->assertStringContainsString('cli-command-card has-detail', $html);
        $this->assertStringContainsString('data-base="repo blob ' . $name . '"', $html);
        $this->assertStringContainsString('cli-field-input', $html);
        $this->assertStringNotContainsString('cli-ssh-link', $html);
        $this->assertStringNotContainsString('href="ssh://', $html);
        $this->assertStringContainsString('data-lang="bash"', $html);
        $this->assertStringContainsString('hl-type', $html);
    }

    public function testEmptyRepositoryStillShowsCloneCommand(): void
    {
        $name = $this->repo($this->user('dave'));

        $response = $this->get('/' . $name);
        $html = (string) $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString($this->sshCloneCommand($name), $html);
        $this->assertStringContainsString('This repository is empty.', $html);
        $this->assertStringNotContainsString('<ul>', $html);
        $this->assertStringNotContainsString('README', $html);
    }

    public function testRepositoryWithoutReadmeShowsTreeAndNotice(): void
    {
        $name = $this->repo($this->user('erin'));
        GitTestHelper::writeFile($this->git($name), 'main', 'main.go', "package main\n", 'add source');

        $response = $this->get('/' . $name);
        $html = (string) $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('main.go', $html);
        $this->assertStringContainsString('This repository has no README.', $html);
        $this->assertStringContainsString($this->sshCloneCommand($name), $html);
    }

    public function testTrailingGitSuffixAndSlashResolveToTheRepository(): void
    {
        $name = $this->repo($this->user('frank'));
        GitTestHelper::writeFile($this->git($name), 'main', 'README.md', "# Suffix\n", 'add readme');

        foreach (['/' . $name . '.git', '/' . $name . '/'] as $uri) {
            $response = $this->get($uri);

            $this->assertSame(200, $response->getStatusCode(), $uri);
            $this->assertStringContainsString('<h1>' . $name . '</h1>', (string) $response->getContent(), $uri);
        }
    }

    public function testNestedRepositoryNameIsServedWithItsFullPath(): void
    {
        $owner = $this->user('lena');
        $name = $owner->username() . '/group-' . bin2hex(random_bytes(3)) . '/project';
        ($this->createRepo)($owner, $name);
        GitTestHelper::writeFile($this->git($name), 'main', 'README.md', "# Nested\n", 'add readme');

        $response = $this->get('/' . $name);
        $html = (string) $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<h1>' . $name . '</h1>', $html);
        $this->assertStringContainsString($this->sshCloneCommand($name), $html);
        $this->assertStringNotContainsString('README.md</a>', $html);

        $raw = $this->get('/' . $name . '/raw/HEAD/README.md');
        $this->assertSame(200, $raw->getStatusCode());
        $this->assertSame("# Nested\n", $raw->getContent());
    }

    public function testPrivateRepositoryIsHiddenFromAnonymousAndOutsidersButServedToOwnerAndAdmin(): void
    {
        $owner = $this->user('grace');
        $outsider = $this->user('heidi');
        // Tokens are issued up front: handling a request resets the entity manager.
        $ownerToken = $this->secret($owner);
        $outsiderToken = $this->secret($outsider);
        $adminToken = $this->secret($this->admin);
        $name = $this->repo($owner, private: true);
        GitTestHelper::writeFile($this->git($name), 'main', 'README.md', "# Secret plans\n", 'add readme');

        $missing = $this->get('/no-such-' . bin2hex(random_bytes(4)));
        $this->assertNotFound($missing);

        $anonymous = $this->get('/' . $name);
        $this->assertNotFound($anonymous);
        $this->assertSame($missing->getContent(), $anonymous->getContent(), 'private and missing must be indistinguishable');

        $this->assertNotFound($this->get('/' . $name, $outsiderToken));
        $this->assertNotFound($this->get('/' . $name, 'not-a-valid-token'));

        $ownerResponse = $this->get('/' . $name, $ownerToken);
        $this->assertSame(200, $ownerResponse->getStatusCode());
        $this->assertStringContainsString('<h1>Secret plans</h1>', (string) $ownerResponse->getContent());

        $adminResponse = $this->get('/' . $name, $adminToken);
        $this->assertSame(200, $adminResponse->getStatusCode());
    }

    public function testMissingAndNonRepositoryPathsAnswerNotFound(): void
    {
        $name = $this->repo($this->user('ivan'));
        GitTestHelper::writeFile($this->git($name), 'main', 'README.md', "# Hi\n", 'add readme');

        $this->assertNotFound($this->get('/no-such-' . bin2hex(random_bytes(4))));
        $this->assertNotFound($this->get('/favicon.ico'));
        // Sub-paths are not repositories (navigation routes are not declared here).
        $this->assertNotFound($this->get('/' . $name . '/pkg/util'));
        // Names that fail validation never reach the store.
        $this->assertNotFound($this->get('/%3Cscript%3Ealert(1)%3C/script%3E'));
        $this->assertNotFound($this->get('/.git'));
    }

    public function testGoGetDiscoveryIsServedUnchanged(): void
    {
        $owner = $this->user('judy');
        $ownerToken = $this->secret($owner);
        $name = $this->repo($owner);
        $privateName = $this->repo($owner, private: true);
        GitTestHelper::writeFile($this->git($name), 'main', 'README.md', "# Go\n", 'add readme');

        // The discovery document is GoGetController's own: go-import meta, not the browse page.
        $discovery = $this->get('/' . $name . '?go-get=1');
        $this->assertSame(200, $discovery->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $discovery->headers->get('Content-Type'));
        $this->assertStringContainsString($this->goImportMeta($name), (string) $discovery->getContent());
        $this->assertStringNotContainsString('<h1>', (string) $discovery->getContent());

        // Sub-package paths walk up to the repository; a trailing .git is ignored.
        foreach (['/' . $name . '/pkg/util?go-get=1', '/' . $name . '.git?go-get=1'] as $uri) {
            $response = $this->get($uri);

            $this->assertSame(200, $response->getStatusCode(), $uri);
            $this->assertStringContainsString($this->goImportMeta($name), (string) $response->getContent(), $uri);
        }

        // 404 otherwise: unknown repository, or one the caller cannot read.
        $this->assertNotFound($this->get('/no-such-' . bin2hex(random_bytes(4)) . '?go-get=1'));
        $this->assertNotFound($this->get('/' . $privateName . '?go-get=1'));
        $ownerResponse = $this->get('/' . $privateName . '?go-get=1', $ownerToken);
        $this->assertSame(200, $ownerResponse->getStatusCode());
        $this->assertStringContainsString($this->goImportMeta($privateName), (string) $ownerResponse->getContent());

        // Any other go-get value is a plain page view, not a discovery document.
        $page = $this->get('/' . $name . '?go-get=0');
        $this->assertSame(200, $page->getStatusCode());
        $this->assertStringNotContainsString('go-import', (string) $page->getContent());
        $this->assertStringContainsString('<h1>' . $name . '</h1>', (string) $page->getContent());
    }

    public function testGitSmartAndDumbRoutesStillWinOverThePage(): void
    {
        $name = $this->repo($this->user('karl'));
        GitTestHelper::writeFile($this->git($name), 'main', 'README.md', "# Git\n", 'add readme');

        $smart = $this->get('/' . $name . '.git/info/refs?service=git-upload-pack');
        $this->assertSame(200, $smart->getStatusCode());
        $this->assertStringContainsString('application/x-git-upload-pack-advertisement', (string) $smart->headers->get('Content-Type'));

        $dumb = $this->get('/' . $name . '.git/HEAD');
        $this->assertSame(200, $dumb->getStatusCode());
        $this->assertStringNotContainsString('text/html', (string) $dumb->headers->get('Content-Type'));
    }

    public function testSpecificGitLfsAndRawRoutesWinOverTheCatchAll(): void
    {
        /** @var RouterInterface $router */
        $router = static::getContainer()->get('router');

        $oid = str_repeat('a', 64);
        $cases = [
            ['GET', '/demo/raw/main/README.md'],
            ['GET', '/demo/deployments/main'],
            ['GET', '/demo/deploy/main'],
            ['GET', '/demo.git/info/refs'],
            ['GET', '/demo/info/refs'],
            ['GET', '/demo.git/HEAD'],
            ['POST', '/demo.git/git-upload-pack'],
            ['POST', '/demo/git-receive-pack'],
            ['POST', '/demo.git/info/lfs/objects/batch'],
            ['GET', '/demo.git/info/lfs/locks'],
            ['GET', '/demo.git/info/lfs/objects/basic/' . $oid],
        ];
        foreach ($cases as [$method, $path]) {
            $router->getContext()->setMethod($method);
            $controller = $router->match($path)['_controller'] ?? null;

            $this->assertTrue(
                \is_string($controller) && !str_contains($controller, 'RepoWebController'),
                sprintf('%s %s must be served by its own route, not the repository page', $method, $path),
            );
        }

        // The catch-all still owns the bare repository URL.
        $router->getContext()->setMethod('GET');
        $controller = $router->match('/demo')['_controller'] ?? null;
        $this->assertTrue(\is_string($controller) && str_contains($controller, 'RepoWebController'));
    }

    public function testRepoPaletteCardsLinkToDocsAndWiki(): void
    {
        $owner = $this->user('palette');
        $name = $this->repo($owner, false, 'Palette test');
        \App\Tests\GitTestHelper::writeFile($this->git($name), 'main', 'README.md', "# Demo\n", 'init');

        $html = (string) $this->get('/' . $name)->getContent();
        $this->assertStringContainsString('href="/docs/repo/tree"', $html);
        $this->assertStringContainsString('href="/wiki/repo-management"', $html);
        $this->assertStringContainsString('href="/wiki/branch-governance"', $html);
    }
}

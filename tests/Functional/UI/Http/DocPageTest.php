<?php

declare(strict_types=1);

namespace App\Tests\Functional\UI\Http;

use App\Identity\Application\IssueAccessToken;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class DocPageTest extends KernelTestCase
{
    private IssueAccessToken $issueToken;
    private User $admin;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var IssueAccessToken $issueToken */
        $issueToken = $container->get(IssueAccessToken::class);
        $this->issueToken = $issueToken;

        /** @var UserStore $userStore */
        $userStore = $container->get(UserStore::class);
        $admin = new User('docadmin-' . bin2hex(random_bytes(3)), true, new \DateTimeImmutable());
        $userStore->save($admin);
        $this->admin = $admin;

        $user = new User('docuser-' . bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $userStore->save($user);
        $this->user = $user;
    }

    private function get(string $uri, ?string $token = null): Response
    {
        $server = null === $token ? [] : ['HTTP_AUTHORIZATION' => 'Token ' . $token];
        $request = Request::create($uri, 'GET', [], [], [], $server);

        $kernel = self::$kernel ?? throw new \LogicException('Kernel not booted');

        return $kernel->handle($request);
    }

    private function secret(User $user): string
    {
        $container = static::getContainer();
        /** @var UserStore $userStore */
        $userStore = $container->get(UserStore::class);
        $freshUser = $userStore->byUsername($user->username()) ?? $user;

        [, $secret] = ($this->issueToken)($freshUser, 'doc-token-' . bin2hex(random_bytes(2)));

        return $secret;
    }

    public function testDocsPageIsServedAtDocsAndHelp(): void
    {
        foreach (['/docs', '/help'] as $uri) {
            $response = $this->get($uri);
            $this->assertSame(200, $response->getStatusCode(), "Failed asserting 200 for {$uri}");
            $html = (string) $response->getContent();

            $this->assertStringContainsString('CLI Command Reference', $html);
            $this->assertStringContainsString('Command Catalog', $html);
            $this->assertStringContainsString('repo tree', $html);
            $this->assertStringContainsString('repo branch protect', $html);
            $this->assertStringContainsString('pubkey list', $html);
            $this->assertStringContainsString('token create', $html);
            $this->assertStringContainsString('copy-btn', $html);
            $this->assertStringNotContainsString('cli-ssh-link', $html);
            $this->assertStringNotContainsString('href="ssh://', $html);
        }
    }

    public function testDocsPageHidesAdminCommandsFromAnonymousOrRegularUsers(): void
    {
        $response = $this->get('/docs');
        $html = (string) $response->getContent();

        $this->assertStringNotContainsString('data-filter="admin"', $html);
        $this->assertStringNotContainsString('data-filter="settings"', $html);
        $this->assertStringNotContainsString('user set-admin', $html);
        $this->assertStringNotContainsString('settings allow-signup', $html);

        // Regular user
        $userToken = $this->secret($this->user);
        $userResponse = $this->get('/docs', $userToken);
        $userHtml = (string) $userResponse->getContent();

        $this->assertStringNotContainsString('data-filter="admin"', $userHtml);
        $this->assertStringNotContainsString('user set-admin', $userHtml);
    }

    public function testDocsPageRevealsAdminCommandsToAdminUsers(): void
    {
        $adminToken = $this->secret($this->admin);
        $response = $this->get('/docs', $adminToken);
        $html = (string) $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('data-filter="admin"', $html);
        $this->assertStringContainsString('data-filter="settings"', $html);
        $this->assertStringContainsString('user set-admin', $html);
        $this->assertStringContainsString('settings allow-signup', $html);
        $this->assertStringContainsString('settings anon-access', $html);
    }

    public function testDocsIndexLinksToIndividualCommandPages(): void
    {
        $response = $this->get('/docs');
        $html = (string) $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('href="/docs/repo/tree"', $html);
        $this->assertStringContainsString('href="/docs/repo/branch/protect"', $html);
        $this->assertStringContainsString('href="/docs/pubkey/add"', $html);
        $this->assertStringNotContainsString('cli-card-caret', $html);
        $this->assertStringNotContainsString('cli-card-detail', $html);
    }

    public function testIndividualCommandPageRendersDetailsBreadcrumbsAndFlags(): void
    {
        foreach (['/docs/repo/branch/protect', '/help/repo/branch/protect', '/docs/repo/deploy/quota', '/help/repo/deploy/quota'] as $uri) {
            $response = $this->get($uri);
            $this->assertSame(200, $response->getStatusCode(), "Failed asserting 200 for {$uri}");
            $html = (string) $response->getContent();

            $this->assertStringContainsString('docs', $html);
            $this->assertStringContainsString('Synopsis', $html);
            $this->assertStringContainsString('Flags &amp; Options', $html);
            $this->assertStringContainsString('Back to', $html);
            $this->assertStringContainsString('copy-btn', $html);
        }
    }

    public function testCommandGroupPageRendersSubcommands(): void
    {
        $response = $this->get('/docs/repo');
        $this->assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();

        $this->assertStringContainsString('Subcommands', $html);
        $this->assertStringContainsString('/docs/repo/tree', $html);
        $this->assertStringContainsString('/docs/repo/branch', $html);
        $this->assertStringContainsString('/docs/repo/create', $html);
    }

    public function testIndividualCommandPageReturns404ForNonexistentCommand(): void
    {
        $response = $this->get('/docs/nonexistent-cmd-xyz');
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testIndividualAdminCommandPageIsProtected(): void
    {
        // Anon user gets 404
        $anonResponse = $this->get('/docs/user/set-admin');
        $this->assertSame(404, $anonResponse->getStatusCode());

        // Regular user gets 404
        $userToken = $this->secret($this->user);
        $userResponse = $this->get('/docs/user/set-admin', $userToken);
        $this->assertSame(404, $userResponse->getStatusCode());

        // Admin user gets 200
        $adminToken = $this->secret($this->admin);
        $adminResponse = $this->get('/docs/user/set-admin', $adminToken);
        $this->assertSame(200, $adminResponse->getStatusCode());
        $adminHtml = (string) $adminResponse->getContent();
        $this->assertStringContainsString('user set-admin', $adminHtml);
    }
}

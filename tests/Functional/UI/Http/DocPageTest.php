<?php

declare(strict_types=1);

namespace App\Tests\Functional\UI\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\IssueAccessToken;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class DocPageTest extends KernelTestCase
{
    private CreateUser $createUser;
    private IssueAccessToken $issueToken;
    private User $admin;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var CreateUser $createUser */
        $createUser = $container->get(CreateUser::class);
        $this->createUser = $createUser;

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
        [, $secret] = ($this->issueToken)($user, 'doc-token');

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
}

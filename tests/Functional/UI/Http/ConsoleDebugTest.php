<?php

declare(strict_types=1);

namespace App\Tests\Functional\UI\Http;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Identity\Infrastructure\WebAuth\WebSessionTokens;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ConsoleDebugTest extends KernelTestCase
{
    private WebSessionTokens $tokens;
    private UserStore $users;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->tokens = $c->get(WebSessionTokens::class);
        $this->users = $c->get(UserStore::class);
    }

    public function testAnonymousAccessIsDenied(): void
    {
        $response = $this->request('GET', '/_dev/console');
        self::assertContains($response->getStatusCode(), [Response::HTTP_FORBIDDEN, Response::HTTP_UNAUTHORIZED]);
    }

    public function testNonAdminAccessIsDenied(): void
    {
        $user = new User('regular-' . bin2hex(random_bytes(4)), false, new \DateTimeImmutable());
        $this->users->save($user);
        $cookie = $this->tokens->issueSession($user);

        $response = $this->request('GET', '/_dev/console', null, $cookie);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testAdminCanAccessConsole(): void
    {
        $admin = new User('admin-' . bin2hex(random_bytes(4)), true, new \DateTimeImmutable());
        $this->users->save($admin);
        $cookie = $this->tokens->issueSession($admin);

        $response = $this->request('GET', '/_dev/console', null, $cookie);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $html = (string) $response->getContent();
        self::assertStringContainsString('Developer Console', $html);
        self::assertStringContainsString('id="terminal"', $html);
        self::assertStringContainsString('id="cli-input"', $html);
        self::assertStringContainsString('CLI Terminal', $html);
        self::assertStringContainsString('Live TUI App', $html);
    }

    public function testTuiRouteRedirectsToConsole(): void
    {
        $admin = new User('admin-' . bin2hex(random_bytes(4)), true, new \DateTimeImmutable());
        $this->users->save($admin);
        $cookie = $this->tokens->issueSession($admin);

        $response = $this->request('GET', '/_dev/tui?repo=my-repo', null, $cookie);
        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertStringContainsString('/_dev/console?repo=my-repo', (string) $response->headers->get('Location'));
    }

    public function testAdminCanAccessComponentsShowcase(): void
    {
        $admin = new User('admin-' . bin2hex(random_bytes(4)), true, new \DateTimeImmutable());
        $this->users->save($admin);
        $cookie = $this->tokens->issueSession($admin);

        $response = $this->request('GET', '/_dev/console/components', null, $cookie);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $html = (string) $response->getContent();
        self::assertStringContainsString('TUI Component Showcase', $html);
    }

    public function testAdminCanExecuteCliCommand(): void
    {
        $admin = new User('admin-' . bin2hex(random_bytes(4)), true, new \DateTimeImmutable());
        $this->users->save($admin);
        $cookie = $this->tokens->issueSession($admin);

        $payload = (string) json_encode([
            'command' => 'help',
            'user' => $admin->username(),
        ]);

        $response = $this->request('POST', '/_dev/console/exec', $payload, $cookie);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertSame(0, $data['exit_code'] ?? -1);
        self::assertNotEmpty($data['output'] ?? '');
        self::assertArrayHasKey('profile', $data);
        self::assertIsArray($data['profile']);
        self::assertArrayHasKey('duration_ms', $data['profile']);
        self::assertArrayHasKey('memory_peak_mb', $data['profile']);
        self::assertSame('help', $data['profile']['command'] ?? '');
    }

    public function testAdminCanSendInspectEventViaInput(): void
    {
        $admin = new User('admin-' . bin2hex(random_bytes(4)), true, new \DateTimeImmutable());
        $this->users->save($admin);
        $cookie = $this->tokens->issueSession($admin);

        $payload = (string) json_encode([
            'session_id' => 'test_session_inspect',
            'inspect' => ['col' => 10, 'row' => 5],
        ]);

        $response = $this->request('POST', '/_dev/console/input', $payload, $cookie);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertTrue($data['ok'] ?? false);
    }

    public function testAdminCanExecuteSshAboutCommand(): void
    {
        $admin = new User('admin-' . bin2hex(random_bytes(4)), true, new \DateTimeImmutable());
        $this->users->save($admin);
        $cookie = $this->tokens->issueSession($admin);

        $payload = (string) json_encode([
            'command' => 'ssh codes.sh about',
            'user' => $admin->username(),
        ]);

        $response = $this->request('POST', '/_dev/console/exec', $payload, $cookie);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertSame(0, $data['exit_code'] ?? -1);
        self::assertStringNotContainsString('No stream arrays were passed', $data['output'] ?? '');
    }

    public function testAdminCanExecutePipedSignupCommand(): void
    {
        $admin = new User('admin-' . bin2hex(random_bytes(4)), true, new \DateTimeImmutable());
        $this->users->save($admin);
        $cookie = $this->tokens->issueSession($admin);

        $randomUser = 'user' . bin2hex(random_bytes(3));
        $payload = (string) json_encode([
            'command' => 'ssh ' . $randomUser . '@codes.sh signup | bash',
            'user' => $admin->username(),
        ]);

        $response = $this->request('POST', '/_dev/console/exec', $payload, $cookie);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertSame(0, $data['exit_code'] ?? -1);
        self::assertStringContainsString('#!/usr/bin/env bash', $data['output'] ?? '');
    }

    public function testAdminCanExecuteUnknownCommand(): void
    {
        $admin = new User('admin-' . bin2hex(random_bytes(4)), true, new \DateTimeImmutable());
        $this->users->save($admin);
        $cookie = $this->tokens->issueSession($admin);

        $payload = (string) json_encode([
            'command' => 'qsd',
            'user' => $admin->username(),
        ]);

        $response = $this->request('POST', '/_dev/console/exec', $payload, $cookie);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertStringContainsString('Unknown command', $data['output'] ?? '');
    }

    public function testAdminCanSendTuiInput(): void
    {
        $admin = new User('admin-' . bin2hex(random_bytes(4)), true, new \DateTimeImmutable());
        $this->users->save($admin);
        $cookie = $this->tokens->issueSession($admin);

        $payload = (string) json_encode([
            'session_id' => 'test_session_123',
            'key' => "\t",
        ]);

        $response = $this->request('POST', '/_dev/console/input', $payload, $cookie);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertTrue($data['ok'] ?? false);
    }

    public function testConsoleReturns404InProduction(): void
    {
        $admin = new User('admin-' . bin2hex(random_bytes(4)), true, new \DateTimeImmutable());
        $this->users->save($admin);
        $cookie = $this->tokens->issueSession($admin);

        self::ensureKernelShutdown();
        self::bootKernel(['environment' => 'prod', 'debug' => false]);

        $response = $this->request('GET', '/_dev/console', null, $cookie);
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    private function request(string $method, string $uri, ?string $content = null, ?string $sessionCookie = null): Response
    {
        $server = [];
        if ($content !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }

        $cookies = null === $sessionCookie ? [] : [WebSessionTokens::COOKIE_NAME => $sessionCookie];
        $request = Request::create($uri, $method, [], $cookies, [], $server, $content);
        $kernel = self::$kernel ?? throw new \LogicException('Kernel not booted');
        $response = $kernel->handle($request);

        if ($kernel instanceof \Symfony\Component\HttpKernel\TerminableInterface) {
            $kernel->terminate($request, $response);
        }

        return $response;
    }
}

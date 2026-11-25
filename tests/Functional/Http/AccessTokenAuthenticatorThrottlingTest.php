<?php

declare(strict_types=1);

namespace App\Tests\Functional\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\SetPassword;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Identity\Infrastructure\Security\LoginThrottler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AccessTokenAuthenticatorThrottlingTest extends KernelTestCase
{
    private UserStore $userStore;
    private SetPassword $setPassword;
    private LoginThrottler $throttler;
    private User $admin;
    private User $targetUser;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();

        $this->userStore = $c->get(UserStore::class);
        $this->setPassword = $c->get(SetPassword::class);
        $this->throttler = $c->get(LoginThrottler::class);

        $admin = new User('http-auth-admin-'.bin2hex(random_bytes(3)), true, new \DateTimeImmutable());
        $this->userStore->save($admin);
        $this->admin = $admin;

        /** @var CreateUser $createUser */
        $createUser = $c->get(CreateUser::class);
        $this->targetUser = $createUser($this->admin, 'victim-'.bin2hex(random_bytes(3)), false);
        ($this->setPassword)($this->admin, $this->targetUser->username(), 'correctpassword');

        $this->throttler->reset($this->targetUser->username(), '127.0.0.1');
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

    public function testHttpBasicAuthBruteForceLockout(): void
    {
        $username = $this->targetUser->username();

        // 5 bad password attempts
        for ($i = 1; $i <= 5; ++$i) {
            $req = Request::create('/browse');
            $req->headers->set('Authorization', 'Basic '.base64_encode($username.':wrongpass'.$i));
            $req->server->set('REMOTE_ADDR', '127.0.0.1');
            $this->handle($req);
        }

        // 6th attempt with correct password should be locked out
        $authReq = Request::create('/browse');
        $authReq->headers->set('Authorization', 'Basic '.base64_encode($username.':correctpassword'));
        $authReq->server->set('REMOTE_ADDR', '127.0.0.1');
        $res = $this->handle($authReq);

        // Access blocked due to lockout (429 Too Many Requests)
        $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $res->getStatusCode());
        $this->assertStringContainsString('Too many failed attempts', (string) $res->getContent());
    }
}

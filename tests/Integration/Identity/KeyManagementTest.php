<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\AddPublicKey;
use App\Identity\Application\AuthenticateSshKey;
use App\Identity\Application\BootstrapServer;
use App\Identity\Application\CreateUser;
use App\Identity\Application\RemovePublicKey;
use App\Identity\Domain\Model\SshKey;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\NotFound;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class KeyManagementTest extends KernelTestCase
{
    private const KEY1 = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIG5Wc48m9aH6z9ZJd51QZ6F7t7Vw8oW1/wQ3zB9yK4b2 test1@host';
    private const KEY2 = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIPe8L730rYQc+QoV9K0bFq8h3K1oW5t7Vw8oW1/wQ3zB test2@host';

    private UserStore $userStore;
    private CreateUser $createUser;
    private AddPublicKey $addPublicKey;
    private RemovePublicKey $removePublicKey;
    private AuthenticateSshKey $authSshKey;
    private BootstrapServer $bootstrapServer;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->userStore = $container->get(UserStore::class);
        $this->createUser = $container->get('test.create_user');
        $this->addPublicKey = $container->get('test.add_public_key');
        $this->removePublicKey = $container->get('test.remove_public_key');
        $this->authSshKey = $container->get('test.authenticate_ssh_key');
        $this->bootstrapServer = $container->get('test.bootstrap_server');
    }

    private function createAdminActor(): User
    {
        $admin = new User('superadmin', true, new \DateTimeImmutable());
        $this->userStore->save($admin);

        return $admin;
    }

    public function testAddAndAuthenticatePublicKey(): void
    {
        $admin = $this->createAdminActor();
        $user = ($this->createUser)($admin, 'alice', false);

        $parsedKey1 = SshKey::parse(self::KEY1);

        // Add public key
        $pk = ($this->addPublicKey)($user, 'alice', self::KEY1);
        $this->assertSame($parsedKey1->fingerprint, $pk->fingerprint());

        // Authenticate SSH key
        $authenticated = ($this->authSshKey)($parsedKey1->fingerprint);
        $this->assertNotNull($authenticated);
        $this->assertSame('alice', $authenticated->username());
    }

    public function testKeyOwnershipConflict(): void
    {
        $admin = $this->createAdminActor();
        $user1 = ($this->createUser)($admin, 'alice', false);
        $user2 = ($this->createUser)($admin, 'bob', false);

        ($this->addPublicKey)($user1, 'alice', self::KEY1);

        // Bob tries to add the exact same public key
        $this->expectException(Conflict::class);
        $this->expectExceptionMessage('already belongs to user "alice"');
        ($this->addPublicKey)($user2, 'bob', self::KEY1);
    }

    public function testRemovePublicKey(): void
    {
        $admin = $this->createAdminActor();
        $user = ($this->createUser)($admin, 'alice', false);
        ($this->addPublicKey)($user, 'alice', self::KEY1);

        $parsedKey1 = SshKey::parse(self::KEY1);
        $this->assertNotNull(($this->authSshKey)($parsedKey1->fingerprint));

        // Remove key
        ($this->removePublicKey)($user, 'alice', self::KEY1);

        $this->assertNull(($this->authSshKey)($parsedKey1->fingerprint));
        // Removing non-existent key throws NotFound
        $this->expectException(NotFound::class);
        $this->expectExceptionMessage('public key not found');
        ($this->removePublicKey)($user, 'alice', self::KEY1);
    }

    public function testBootstrapServer(): void
    {
        // Run bootstrap
        ($this->bootstrapServer)();

        $admin = $this->userStore->byUsername('admin');
        $this->assertNotNull($admin);
        $this->assertTrue($admin->isAdmin());

        // Idempotent second call
        ($this->bootstrapServer)();
        $adminAgain = $this->userStore->byUsername('admin');
        $this->assertNotNull($adminAgain);
        $this->assertSame($admin->id(), $adminAgain->id());
    }
}

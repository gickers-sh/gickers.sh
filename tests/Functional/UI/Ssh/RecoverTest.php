<?php

declare(strict_types=1);

namespace App\Tests\Functional\UI\Ssh;

use App\Access\Application\ServerPolicy;
use App\Access\Domain\AccessLevel;
use App\Identity\Application\CreateUser;
use App\Identity\Domain\Model\SshKey;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class RecoverTest extends KernelTestCase
{
    private const KEY_ADMIN = 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQC3r8+6/gT8q9Qv7vv3r4u/11AA22BB33CC44DD55EE66FF77AA88BB99CC00DD11EE22FF33AA44BB55CC66DD77EE88FF99AA00BB11CC22DD33EE44FF55AA66BB77CC88DD99EE00FF11AA22BB33CC44DD55EE66FF77AA88BB99CC00DD11EE22FF33AA44BB55CC66DD77EE88FF99AA00BB11CC22DD33EE44FF admin@gickers.sh';
    private const KEY_CHARLIE = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIC4uLi4uLi4uLi4uLi4uLi4uLi4uLi4uLi4uLi4uLi4uLi4u charlie@gickers.sh';

    private string $adminFp;
    private string $charlieFp;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->adminFp = SshKey::parse(self::KEY_ADMIN)->fingerprint;
        $this->charlieFp = SshKey::parse(self::KEY_CHARLIE)->fingerprint;

        /** @var UserStore $userStore */
        $userStore = $container->get(UserStore::class);
        /** @var CreateUser $createUser */
        $createUser = $container->get(CreateUser::class);
        /** @var ServerPolicy $serverPolicy */
        $serverPolicy = $container->get(ServerPolicy::class);

        $serverPolicy->setAllowKeyless(true);
        $serverPolicy->setAnonAccess(AccessLevel::ReadOnlyAccess);

        $admin = $userStore->byUsername('admin');
        if (null === $admin) {
            $admin = new User('admin', true, new \DateTimeImmutable());
            $admin->addPublicKey(SshKey::parse(self::KEY_ADMIN), new \DateTimeImmutable());
            $userStore->save($admin);
        }

        $charlie = $userStore->byUsername('charlie');
        if (null === $charlie) {
            $createUser($admin, 'charlie', false, [self::KEY_CHARLIE]);
        } else {
            $charlie->changePasswordHash(null, new \DateTimeImmutable());
            $userStore->save($charlie);
        }

        /** @var \App\Identity\Infrastructure\Security\LoginThrottler $throttler */
        $throttler = $container->get(\App\Identity\Infrastructure\Security\LoginThrottler::class);
        $throttler->reset('charlie', '127.0.0.1');
    }

    protected function tearDown(): void
    {
        if (self::$booted) {
            $container = self::getContainer();
            /** @var \App\Identity\Infrastructure\Security\LoginThrottler $throttler */
            $throttler = $container->get(\App\Identity\Infrastructure\Security\LoginThrottler::class);
            $throttler->reset('charlie', '127.0.0.1');
        }
        parent::tearDown();
    }

    /**
     * @param list<string>|null $inputs
     */
    private function dispatch(
        string $cmd,
        ?string $fingerprint = null,
        ?array $inputs = null,
        ?string $user = null,
    ): CommandTester {
        $application = new Application(self::$kernel);
        $command = $application->find('soft:ssh:dispatch');

        $tester = new CommandTester($command);
        $params = ['original-command' => $cmd];
        if (null !== $fingerprint) {
            if (str_starts_with($fingerprint, 'ssh-') || str_starts_with($fingerprint, 'ecdsa-')) {
                $params['--key'] = $fingerprint;
            } else {
                $params['--key-fingerprint'] = $fingerprint;
            }
        }
        if (null !== $user) {
            $params['--user'] = $user;
        }

        if (null !== $inputs) {
            $tester->setInputs($inputs);
        }

        $options = ['capture_stderr_separately' => true];
        $tester->execute($params, $options);

        return $tester;
    }

    private function getOutputText(CommandTester $tester): string
    {
        return $tester->getDisplay().$tester->getErrorOutput();
    }

    public function testSetPasswordAndRecoverWithNewKey(): void
    {
        // 1. Charlie sets their backup password
        $setPw = $this->dispatch('user password --password=superrecovery123', $this->charlieFp);
        $this->assertSame(0, $setPw->getStatusCode());
        $this->assertStringContainsString('Backup password successfully set for @charlie', $setPw->getDisplay());

        // Verify password hash in DB
        /** @var UserStore $userStore */
        $userStore = self::getContainer()->get(UserStore::class);
        $charlie = $userStore->byUsername('charlie');
        $this->assertNotNull($charlie);
        $this->assertNotNull($charlie->passwordHash());

        // 2. Charlie lost their SSH key and presents a brand new key
        $kp = sodium_crypto_sign_keypair();
        $pk = sodium_crypto_sign_publickey($kp);
        $newKey = 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).$pk).' new-laptop@test';

        // 2a. Attempt recovery with wrong password
        $failTester = $this->dispatch('recover charlie --password=wrongpassword', $newKey);
        $this->assertNotSame(0, $failTester->getStatusCode());

        // 2b. Attempt recovery with correct password
        $successTester = $this->dispatch('recover charlie --password=superrecovery123', $newKey);
        $this->assertSame(0, $successTester->getStatusCode());
        $this->assertStringContainsString('Identity verified', $successTester->getDisplay());
        $this->assertStringContainsString('New SSH key registered for @charlie', $successTester->getDisplay());

        // 3. Verify that the new key is now registered on charlie
        $newFp = SshKey::parse($newKey)->fingerprint;
        $updatedUser = $userStore->byUsername('charlie');
        $this->assertNotNull($updatedUser);
        $hasKey = false;
        foreach ($updatedUser->publicKeys() as $k) {
            if ($k->fingerprint() === $newFp) {
                $hasKey = true;
                break;
            }
        }
        $this->assertTrue($hasKey, 'New key should be attached to charlie');

        // Charlie can now run commands with their new key
        $infoTester = $this->dispatch('info', $newFp);
        $this->assertSame(0, $infoTester->getStatusCode());
    }

    public function testPasswordRepeatConfirmationMismatch(): void
    {
        // Interactive mismatch
        $mismatchTester = $this->dispatch('user password', $this->charlieFp, inputs: ['firstpwd123', 'secondpwd123']);
        $this->assertNotSame(0, $mismatchTester->getStatusCode());
        $this->assertStringContainsString('Passwords do not match', $this->getOutputText($mismatchTester));
    }

    public function testChangeExistingPasswordRequiresCurrentPassword(): void
    {
        // Set initial password
        $this->dispatch('user password --password=initialpass123', $this->charlieFp);

        // Attempting to change without correct old password fails
        $failChange = $this->dispatch('user password --old-password=wrongpass --password=newsecret456', $this->charlieFp);
        $this->assertNotSame(0, $failChange->getStatusCode());
        $this->assertStringContainsString('Incorrect current password', $this->getOutputText($failChange));

        // Attempting to change with correct old password succeeds
        $successChange = $this->dispatch('user password --old-password=initialpass123 --password=newsecret456', $this->charlieFp);
        $this->assertSame(0, $successChange->getStatusCode());
        $this->assertStringContainsString('Backup password successfully updated for @charlie', $this->getOutputText($successChange));

        // Interactive update with old password + new password + confirmation also succeeds
        $interactiveChange = $this->dispatch('user password', $this->charlieFp, inputs: ['newsecret456', 'interactive789', 'interactive789']);
        $this->assertSame(0, $interactiveChange->getStatusCode());
        $this->assertStringContainsString('Backup password successfully updated for @charlie', $this->getOutputText($interactiveChange));

        // Verify recovery with new password
        $kp = sodium_crypto_sign_keypair();
        $pk = sodium_crypto_sign_publickey($kp);
        $key3 = 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).$pk).' key3@test';

        // Old password no longer works
        $oldFail = $this->dispatch('recover charlie --password=initialpass123', $key3);
        $this->assertNotSame(0, $oldFail->getStatusCode());

        // New password works
        $newOk = $this->dispatch('recover charlie --password=interactive789', $key3);
        $this->assertSame(0, $newOk->getStatusCode());
    }

    public function testRecoverBruteForceLockout(): void
    {
        $this->dispatch('user password --password=correctpassword123', $this->charlieFp);

        $kp = sodium_crypto_sign_keypair();
        $pk = sodium_crypto_sign_publickey($kp);
        $attackerKey = 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).$pk).' attacker@evil';

        // 5 consecutive failed password attempts
        for ($i = 1; $i <= 5; ++$i) {
            $res = $this->dispatch('recover charlie --password=wrongpass'.$i, $attackerKey);
            $this->assertNotSame(0, $res->getStatusCode());
            $this->assertStringContainsString('Invalid backup password', $this->getOutputText($res));
        }

        // 6th attempt is locked out immediately, even with the CORRECT password!
        $lockedRes = $this->dispatch('recover charlie --password=correctpassword123', $attackerKey);
        $this->assertNotSame(0, $lockedRes->getStatusCode());
        $this->assertStringContainsString('Too many failed attempts', $this->getOutputText($lockedRes));
    }
}

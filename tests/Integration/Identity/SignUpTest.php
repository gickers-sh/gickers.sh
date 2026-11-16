<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Access\Application\ServerPolicy;
use App\Identity\Application\SignUp;
use App\Identity\Domain\Model\SshKey;
use App\Identity\Domain\UserStore;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\Conflict;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SignUpTest extends KernelTestCase
{
    private UserStore $userStore;
    private ServerPolicy $serverPolicy;
    private SignUp $signUp;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->userStore = $container->get(UserStore::class);
        $this->serverPolicy = $container->get(ServerPolicy::class);
        $this->signUp = $container->get('test.sign_up');

        $this->serverPolicy->setAllowSignup(true);
    }

    private static function freshName(): string
    {
        return 'signup' . bin2hex(random_bytes(5));
    }

    private static function generateKey(): string
    {
        $dir = sys_get_temp_dir() . '/codes-signup-' . bin2hex(random_bytes(6));
        $path = $dir . '/id_ed25519';
        mkdir($dir, 0700, true);
        exec(sprintf('ssh-keygen -q -t ed25519 -N %s -C %s -f %s', escapeshellarg(''), escapeshellarg('test@codes.sh'), escapeshellarg($path)), $out, $code);
        if (0 !== $code) {
            self::fail('ssh-keygen failed');
        }
        $line = trim((string) file_get_contents($path . '.pub'));
        @unlink($path);
        @unlink($path . '.pub');
        @rmdir($dir);

        return $line;
    }

    public function testSignupCreatesUserBoundToPresentedKey(): void
    {
        $keyLine = self::generateKey();
        $username = self::freshName();

        $user = ($this->signUp)($username, $keyLine);

        self::assertSame($username, $user->username());
        self::assertFalse($user->isAdmin());

        $fingerprint = SshKey::parse($keyLine)->fingerprint;
        $byFingerprint = $this->userStore->byFingerprint($fingerprint);
        self::assertNotNull($byFingerprint);
        self::assertSame($user->id(), $byFingerprint->id());
    }

    public function testSignupAllowsSameKeyForDifferentUsers(): void
    {
        $keyLine = self::generateKey();
        $user1 = ($this->signUp)(self::freshName(), $keyLine);
        $user2 = ($this->signUp)(self::freshName(), $keyLine);

        self::assertNotSame($user1->id(), $user2->id());
        self::assertCount(2, $this->userStore->allByFingerprint(SshKey::parse($keyLine)->fingerprint));
    }

    public function testSignupRejectsTakenUsername(): void
    {
        $username = self::freshName();
        ($this->signUp)($username, self::generateKey());

        $this->expectException(Conflict::class);
        $this->expectExceptionMessage('already exists');
        ($this->signUp)($username, self::generateKey());
    }

    public function testSignupDeniedWhenDisabled(): void
    {
        $this->serverPolicy->setAllowSignup(false);

        try {
            $this->expectException(AccessDenied::class);
            ($this->signUp)(self::freshName(), self::generateKey());
        } finally {
            $this->serverPolicy->setAllowSignup(true);
        }
    }
}

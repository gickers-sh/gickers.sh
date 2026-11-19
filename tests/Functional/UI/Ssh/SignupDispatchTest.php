<?php

declare(strict_types=1);

namespace App\Tests\Functional\UI\Ssh;

use App\Access\Application\ServerPolicy;
use App\Identity\Domain\Model\SshKey;
use App\Identity\Domain\UserStore;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SignupDispatchTest extends KernelTestCase
{
    private UserStore $userStore;
    private ServerPolicy $serverPolicy;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->userStore = $container->get(UserStore::class);
        $this->serverPolicy = $container->get(ServerPolicy::class);
        $this->serverPolicy->setAllowSignup(true);
        // Keyless disabled: an unknown *key* must still reach signup.
        $this->serverPolicy->setAllowKeyless(false);
    }

    private static function generateKey(): string
    {
        $dir = sys_get_temp_dir().'/codes-signup-'.bin2hex(random_bytes(6));
        $path = $dir.'/id_ed25519';
        mkdir($dir, 0700, true);
        exec(sprintf('ssh-keygen -q -t ed25519 -N %s -C %s -f %s', escapeshellarg(''), escapeshellarg('test@codes.sh'), escapeshellarg($path)), $out, $code);
        self::assertSame(0, $code, 'ssh-keygen failed');
        $line = trim((string) file_get_contents($path.'.pub'));
        @unlink($path);
        @unlink($path.'.pub');
        @rmdir($dir);

        return $line;
    }

    private function dispatch(string $cmd, string $rawKey): CommandTester
    {
        $application = new Application(self::$kernel);
        $command = $application->find('soft:ssh:dispatch');
        $tester = new CommandTester($command);
        $tester->execute(
            ['original-command' => $cmd, '--key' => $rawKey],
            ['capture_stderr_separately' => true],
        );

        return $tester;
    }

    private static function text(CommandTester $tester): string
    {
        return $tester->getDisplay().$tester->getErrorOutput();
    }

    public function testUnknownKeyCanSignUp(): void
    {
        $key = self::generateKey();
        $username = 'disp'.bin2hex(random_bytes(5));

        $tester = $this->dispatch('signup '.$username, $key);

        self::assertSame(0, $tester->getStatusCode(), self::text($tester));
        self::assertStringContainsString('registered', self::text($tester));

        $user = $this->userStore->byFingerprint(SshKey::parse($key)->fingerprint);
        self::assertNotNull($user);
        self::assertSame($username, $user->username());
    }

    public function testKnownKeyCanSignUpAnotherUsername(): void
    {
        $key = self::generateKey();
        $user1 = 'user1'.bin2hex(random_bytes(4));
        $user2 = 'user2'.bin2hex(random_bytes(4));

        // First signup
        $tester1 = $this->dispatch('signup '.$user1, $key);
        self::assertSame(0, $tester1->getStatusCode(), self::text($tester1));

        // Second signup with the exact same key
        $tester2 = $this->dispatch('signup '.$user2, $key);
        self::assertSame(0, $tester2->getStatusCode(), self::text($tester2));
        self::assertStringContainsString('registered', self::text($tester2));

        $fp = SshKey::parse($key)->fingerprint;
        self::assertNotNull($this->userStore->byFingerprintAndUsername($fp, $user1));
        self::assertNotNull($this->userStore->byFingerprintAndUsername($fp, $user2));
    }

    public function testUnknownKeyIsRestrictedToSignup(): void
    {
        $tester = $this->dispatch('repo list', self::generateKey());

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('signup', self::text($tester));
    }

    public function testUnknownKeyCannotStartTui(): void
    {
        $tester = $this->dispatch('', self::generateKey());

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringNotContainsString('Launch TUI', self::text($tester));
        self::assertStringContainsString('signup', self::text($tester));
    }

    public function testSignupRegistersExplicitKeyOption(): void
    {
        $sessionKey = self::generateKey();
        $explicitKey = self::generateKey();
        $username = 'disp'.bin2hex(random_bytes(5));

        $tester = $this->dispatch(sprintf('signup %s -k %s', $username, escapeshellarg($explicitKey)), $sessionKey);

        self::assertSame(0, $tester->getStatusCode(), self::text($tester));

        $user = $this->userStore->byFingerprint(SshKey::parse($explicitKey)->fingerprint);
        self::assertNotNull($user);
        self::assertSame($username, $user->username());
        // The session key was not the one registered.
        self::assertNull($this->userStore->byFingerprint(SshKey::parse($sessionKey)->fingerprint));
    }

    public function testUnknownKeyHelpOnlyShowsSignup(): void
    {
        foreach (['help', '-h', '--help'] as $cmd) {
            $tester = $this->dispatch($cmd, self::generateKey());
            self::assertSame(0, $tester->getStatusCode(), $cmd.': '.self::text($tester));
            $out = $tester->getDisplay();
            self::assertStringContainsString('Available Commands:', $out);
            self::assertStringContainsString('signup', $out);
            foreach (['repo', 'user', 'settings', 'token', 'pubkey', 'jwt', 'set-username'] as $hiddenCmd) {
                self::assertStringNotContainsString("\n  ".$hiddenCmd, $out, sprintf('"%s" exposed "%s"', $cmd, $hiddenCmd));
            }
        }
    }

    public function testUnknownKeyCanViewSignupHelp(): void
    {
        foreach (['help signup', 'signup --help', 'signup -h'] as $cmd) {
            $tester = $this->dispatch($cmd, self::generateKey());
            self::assertSame(0, $tester->getStatusCode(), $cmd.': '.self::text($tester));
            $out = $tester->getDisplay();
            self::assertStringContainsString('signup USERNAME', $out);
            self::assertStringContainsString('--key', $out);
        }
    }

    public function testUnknownKeyRejectsHelpAndTransportBypasses(): void
    {
        $blocked = [
            'tui',
            '-t',
            '-t demo',
            'repo create -h',
            'repo list --help',
            'help repo',
            'git-upload-pack demo -h',
            'git-upload-pack demo',
            'git-receive-pack demo',
            'git-upload-archive demo',
            'git-lfs-authenticate demo upload',
            'info --help',
            'settings allow-signup --help',
        ];

        foreach ($blocked as $cmd) {
            $tester = $this->dispatch($cmd, self::generateKey());
            self::assertSame(1, $tester->getStatusCode(), sprintf('Command "%s" should be rejected for unknown key', $cmd));
            self::assertStringContainsString('not registered', self::text($tester), $cmd);
        }
    }

    public function testUnknownKeyDeniedImmediatelyWhenSignupDisabled(): void
    {
        $this->serverPolicy->setAllowSignup(false);
        $key = self::generateKey();
        $username = 'disabled'.bin2hex(random_bytes(4));

        foreach (['signup '.$username, 'help', '--help', ''] as $cmd) {
            $tester = $this->dispatch($cmd, $key);
            self::assertSame(1, $tester->getStatusCode(), $cmd);
            self::assertStringContainsString('Error: unauthorized', self::text($tester), $cmd);
        }

        self::assertNull($this->userStore->byFingerprint(SshKey::parse($key)->fingerprint));
    }
}

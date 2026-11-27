<?php

declare(strict_types=1);

namespace App\Tests\Functional\UI\Ssh;

use App\Access\Application\ServerPolicy;
use App\Identity\Domain\Model\SshKey;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Shared\Infrastructure\Config\ServerSettings;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SetupCommandTest extends KernelTestCase
{
    private UserStore $userStore;
    private ServerPolicy $serverPolicy;
    private ServerSettings $serverSettings;
    private ClockInterface $clock;
    private User $user;
    private string $rawKey;
    private string $expectedHost;

    private static function generateKey(): string
    {
        $dir = sys_get_temp_dir().'/codes-setup-'.bin2hex(random_bytes(6));
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

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->userStore = $container->get(UserStore::class);
        $this->serverPolicy = $container->get(ServerPolicy::class);
        $this->serverSettings = $container->get(ServerSettings::class);
        $this->clock = $container->get(ClockInterface::class);

        $this->serverPolicy->setAllowSignup(true);
        $this->serverPolicy->setAllowKeyless(false);

        $parsed = parse_url($this->serverSettings->sshPublicUrl);
        $this->expectedHost = $parsed['host'] ?? 'codes.sh';

        $this->rawKey = self::generateKey();
        $key = SshKey::parse($this->rawKey);

        $now = $this->clock->now();
        $this->user = new User('setupuser'.bin2hex(random_bytes(4)), false, $now);
        $this->user->addPublicKey($key, $now);
        $this->userStore->save($this->user);
    }

    private function dispatch(string $cmd, ?string $rawKey = null): CommandTester
    {
        $application = new Application(self::$kernel);
        $command = $application->find('soft:ssh:dispatch');
        $tester = new CommandTester($command);
        $tester->execute(
            ['original-command' => $cmd, '--key' => $rawKey ?? $this->rawKey],
            ['capture_stderr_separately' => true],
        );

        return $tester;
    }

    public function testSetupRawOutputsCleanConfigSnippet(): void
    {
        $tester = $this->dispatch('setup --raw');
        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $out = $tester->getDisplay();

        self::assertStringContainsString('Host '.$this->expectedHost, $out);
        self::assertStringContainsString('IdentitiesOnly yes', $out);
        self::assertStringContainsString('RequestTTY auto', $out);
        self::assertStringContainsString('SendEnv COLUMNS LINES TERM COLORTERM', $out);
        self::assertStringNotContainsString('ControlMaster auto', $out);
        self::assertStringContainsString(sprintf('Match host %s user %s', $this->expectedHost, $this->user->username()), $out);
    }

    public function testSetupRawOutputsControlMasterInProdEnvironment(): void
    {
        $generator = new \App\UI\Ssh\Setup\SetupScriptGenerator($this->serverSettings, 'prod');
        $rawConfig = $generator->generateRawConfig($this->expectedHost, 22, $this->user->username(), null);

        self::assertStringContainsString('ControlMaster auto', $rawConfig);
        self::assertStringContainsString('ControlPath ~/.ssh/cm-%r@%h:%p', $rawConfig);
        self::assertStringContainsString('ControlPersist 10m', $rawConfig);
    }

    public function testConfigAliasWorksIdenticalToSetup(): void
    {
        $tester = $this->dispatch('config --raw');
        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $out = $tester->getDisplay();

        self::assertStringContainsString('RequestTTY auto', $out);
        self::assertStringContainsString('Match host '.$this->expectedHost, $out);
    }

    public function testSetupBashOutputsInteractiveBashScriptWithTermstrapAndMenu(): void
    {
        $tester = $this->dispatch('setup --bash');
        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $out = $tester->getDisplay();

        self::assertStringContainsString('#!/usr/bin/env bash', $out);
        self::assertStringContainsString('/dev/tty', $out);
        self::assertStringContainsString('select_menu', $out);
        self::assertStringContainsString('spin', $out);
        self::assertStringContainsString('codes.sh', $out);
        self::assertStringContainsString('RequestTTY auto', $out);
        self::assertStringContainsString('SendEnv COLUMNS LINES TERM COLORTERM', $out);
        self::assertStringContainsString('export COLUMNS LINES', $out);
        self::assertStringContainsString('ACTIVE_CONFIG=$(grep -v', $out);
        self::assertStringContainsString('prompt_password_with_confirm', $out);
        self::assertStringContainsString('prompt_key_path_with_collision_check', $out);
        self::assertStringContainsString('gum_paste_pubkey', $out);
        self::assertStringContainsString('OPT_KEYS=', $out);
        self::assertStringContainsString('MISSING_LINES=', $out);
        self::assertStringNotContainsString('\$CONFIG_FILE', $out);
        self::assertStringContainsString('Match host ${HOST} user ${USERNAME}', $out);
        self::assertStringContainsString(sprintf('USERNAME="%s"', $this->user->username()), $out);
    }

    public function testSetupPs1OutputsPowerShellScriptWithIcaclsAndMenu(): void
    {
        $tester = $this->dispatch('setup --ps1');
        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $out = $tester->getDisplay();

        self::assertStringContainsString('$ErrorActionPreference = "Stop"', $out);
        self::assertStringContainsString('icacls.exe', $out);
        self::assertStringContainsString('Select-Menu', $out);
        self::assertStringContainsString('codes.sh', $out);
        self::assertStringContainsString('RequestTTY auto', $out);
        self::assertStringContainsString('SendEnv COLUMNS LINES TERM COLORTERM', $out);
        self::assertStringContainsString('$env:COLUMNS', $out);
        self::assertStringContainsString('Gum-PasswordWithConfirm', $out);
        self::assertStringContainsString('Gum-PastePubkey', $out);
        self::assertStringContainsString('$activeLines', $out);
        self::assertStringContainsString('Match host $hostName user $username', $out);
        self::assertStringContainsString(sprintf('$username = "%s"', $this->user->username()), $out);
    }

    public function testSignupOutputRecommendsSignupPipeCommand(): void
    {
        $newKey = self::generateKey();
        $newUsername = 'newbie'.bin2hex(random_bytes(4));

        $tester = $this->dispatch('signup '.$newUsername, $newKey);
        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $out = $tester->getDisplay();

        self::assertStringContainsString('signup '.$newUsername.' | bash', $out);
        self::assertStringContainsString('signup '.$newUsername.' | iex', $out);
    }

    public function testSignupOutputsCompleteBashSetupScriptWhenPiped(): void
    {
        $newKey = self::generateKey();
        $newUsername = 'pipeuser'.bin2hex(random_bytes(4));

        putenv('SSH_PIPED=1');
        $_SERVER['SSH_PIPED'] = '1';
        try {
            $tester = $this->dispatch('signup '.$newUsername, $newKey);
            self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
            $out = $tester->getDisplay();

            self::assertStringContainsString('#!/usr/bin/env bash', $out);
            self::assertStringContainsString('gum_choose', $out);
            self::assertStringContainsString('gum_spin', $out);
            self::assertStringContainsString('RequestTTY auto', $out);
            self::assertStringContainsString('Match host ${HOST} user ${USERNAME}', $out);
            self::assertStringContainsString(sprintf('USERNAME="%s"', $newUsername), $out);
        } finally {
            unset($_SERVER['SSH_PIPED'], $_ENV['SSH_PIPED']);
            putenv('SSH_PIPED');
        }
    }

    public function testSignupPipedOutputsCompleteBashSetupScript(): void
    {
        $newKey = self::generateKey();
        $newUsername = 'pipeuser2'.bin2hex(random_bytes(4));

        $tester = $this->dispatch('signup '.$newUsername.' --bash', $newKey);
        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $out = $tester->getDisplay();

        self::assertStringContainsString('#!/usr/bin/env bash', $out);
        self::assertStringContainsString('gum_choose', $out);
        self::assertStringContainsString('gum_spin', $out);
        self::assertStringContainsString('RequestTTY auto', $out);
        self::assertStringContainsString('Match host ${HOST} user ${USERNAME}', $out);
        self::assertStringContainsString(sprintf('USERNAME="%s"', $newUsername), $out);
    }

    public function testSignupWithPipeBashInCommandLineOutputsSetupScript(): void
    {
        $newKey = self::generateKey();
        $newUsername = 'pipebash'.bin2hex(random_bytes(4));

        $tester = $this->dispatch('signup '.$newUsername.' | bash', $newKey);
        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $out = $tester->getDisplay();

        self::assertStringContainsString('#!/usr/bin/env bash', $out);
        self::assertStringContainsString('gum_choose', $out);
        self::assertStringContainsString('Match host ${HOST} user ${USERNAME}', $out);
        self::assertStringContainsString(sprintf('USERNAME="%s"', $newUsername), $out);
    }

    public function testSignupWithPipeIexInCommandLineOutputsPowerShellScript(): void
    {
        $newKey = self::generateKey();
        $newUsername = 'pipeiex'.bin2hex(random_bytes(4));

        $tester = $this->dispatch('signup '.$newUsername.' | iex', $newKey);
        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $out = $tester->getDisplay();

        self::assertStringContainsString('Gum-Choose', $out);
        self::assertStringContainsString('Match host $hostName user $username', $out);
        self::assertStringContainsString(sprintf('$username = "%s"', $newUsername), $out);
    }

    public function testExistingUserCanRerunSignupPipeToReconfigure(): void
    {
        // $this->user already exists and has $this->rawKey
        $tester = $this->dispatch('signup '.$this->user->username().' --bash', $this->rawKey);
        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $out = $tester->getDisplay();

        self::assertStringContainsString('#!/usr/bin/env bash', $out);
        self::assertStringContainsString('Match host ${HOST} user ${USERNAME}', $out);
        self::assertStringContainsString(sprintf('USERNAME="%s"', $this->user->username()), $out);
    }

    public function testSignupWithCustomKeyRecommendsPrivateKeyPathFlag(): void
    {
        $sessionKey = self::generateKey();
        $customKey = self::generateKey();
        $newUsername = 'custom'.bin2hex(random_bytes(4));

        $tester = $this->dispatch(sprintf('signup %s --key %s --register', $newUsername, escapeshellarg($customKey)), $sessionKey);
        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $out = $tester->getDisplay();

        self::assertStringContainsString('registered successfully', $out);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Functional\UI\Ssh;

use App\Access\Application\ServerPolicy;
use App\Identity\Domain\Model\SshKey;
use App\Identity\Domain\UserStore;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class InstallDispatchTest extends KernelTestCase
{
    private UserStore $userStore;
    private ServerPolicy $serverPolicy;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->userStore = $container->get(UserStore::class);
        $this->serverPolicy = $container->get(ServerPolicy::class);
        $this->serverPolicy->setAllowSignup(false);
        $this->serverPolicy->setAllowKeyless(false);
    }

    private static function generateKey(): string
    {
        $dir = sys_get_temp_dir() . '/codes-install-' . bin2hex(random_bytes(6));
        $path = $dir . '/id_ed25519';
        mkdir($dir, 0700, true);
        exec(sprintf('ssh-keygen -q -t ed25519 -N %s -C %s -f %s', escapeshellarg(''), escapeshellarg('install@gickers.sh'), escapeshellarg($path)), $out, $code);
        self::assertSame(0, $code, 'ssh-keygen failed');
        $line = trim((string) file_get_contents($path . '.pub'));
        @unlink($path);
        @unlink($path . '.pub');
        @rmdir($dir);

        return $line;
    }

    private function dispatch(string $cmd, ?string $rawKey = null): CommandTester
    {
        $application = new Application(self::$kernel);
        $command = $application->find('soft:ssh:dispatch');
        $tester = new CommandTester($command);
        $args = ['original-command' => $cmd];
        if (null !== $rawKey) {
            $args['--key'] = $rawKey;
        }
        $tester->execute($args, ['capture_stderr_separately' => true]);

        return $tester;
    }

    public function testInstallBashOutputsScriptEvenWhenSignupAndKeylessDisabled(): void
    {
        $tester = $this->dispatch('install --bash');

        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $out = $tester->getDisplay();

        self::assertStringContainsString('#!/usr/bin/env bash', $out);
        self::assertStringContainsString('gickers.sh — One-Shot Production Installer', $out);
        self::assertStringContainsString('gum_choose', $out);
        self::assertStringContainsString('gum_input', $out);
        self::assertStringContainsString('docker compose --env-file .env.docker', $out);
    }

    public function testInstallPipedBash(): void
    {
        $tester = $this->dispatch('install | bash');

        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $out = $tester->getDisplay();

        self::assertStringContainsString('#!/usr/bin/env bash', $out);
        self::assertStringContainsString('gum_spin', $out);
        self::assertStringContainsString('docker compose', $out);
    }

    public function testInstallPipedIexOutputsPowerShellScript(): void
    {
        $tester = $this->dispatch('install | iex');

        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $out = $tester->getDisplay();

        self::assertStringContainsString('Gum-Choose', $out);
        self::assertStringContainsString('Gum-Input', $out);
        self::assertStringContainsString('docker compose', $out);
    }

    public function testInstallerAliasOutputsInstallerScript(): void
    {
        $tester = $this->dispatch('installer --bash');

        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $out = $tester->getDisplay();

        self::assertStringContainsString('#!/usr/bin/env bash', $out);
        self::assertStringContainsString('gickers.sh — One-Shot Production Installer', $out);
    }

    public function testInstallWithOptionsPreservesValuesInScript(): void
    {
        $key = self::generateKey();
        $tester = $this->dispatch('install --domain custom.forge.io --ssh-port 2223 --admin-user alice --yes --bash', $key);

        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $out = $tester->getDisplay();

        self::assertStringContainsString('custom.forge.io', $out);
        self::assertStringContainsString('SSH_PORT="2223"', $out);
        self::assertStringContainsString('ADMIN_USER="alice"', $out);
        self::assertStringContainsString('NON_INTERACTIVE="1"', $out);
    }
}

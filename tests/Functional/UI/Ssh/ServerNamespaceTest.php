<?php

declare(strict_types=1);

namespace App\Tests\Functional\UI\Ssh;

use App\Access\Application\ServerPolicy;
use App\Identity\Domain\Model\SshKey;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ServerNamespaceTest extends KernelTestCase
{
    private UserStore $userStore;
    private ServerPolicy $serverPolicy;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->userStore = $container->get(UserStore::class);
        $this->serverPolicy = $container->get(ServerPolicy::class);
    }

    private function dispatch(string $cmd, ?string $rawKey = null): CommandTester
    {
        self::assertNotNull(self::$kernel);
        $application = new Application(self::$kernel);
        $command = $application->find('soft:ssh:dispatch');
        $tester = new CommandTester($command);
        $args = ['original-command' => $cmd];
        if (null !== $rawKey) {
            $args['--key'] = $rawKey;
        }

        $tester->execute($args);

        return $tester;
    }

    private static function generateKey(): string
    {
        $dir = sys_get_temp_dir() . '/codes-server-test-' . bin2hex(random_bytes(6));
        $path = $dir . '/id_ed25519';
        mkdir($dir, 0700, true);
        exec(sprintf('ssh-keygen -q -t ed25519 -N %s -C %s -f %s', escapeshellarg(''), escapeshellarg('server@gickers.sh'), escapeshellarg($path)), $out, $code);
        self::assertSame(0, $code, 'ssh-keygen failed');
        $line = trim((string) file_get_contents($path . '.pub'));
        @unlink($path);
        @unlink($path . '.pub');
        @rmdir($dir);

        return $line;
    }

    public function testServerStatusDisplaysMetricsForAdmin(): void
    {
        $key = self::generateKey();
        $admin = new User(
            'admin-' . bin2hex(random_bytes(4)),
            true, // admin
            new \DateTimeImmutable(),
        );
        $admin->addPublicKey(SshKey::parse($key), new \DateTimeImmutable());
        $this->userStore->save($admin);

        $tester = $this->dispatch('server status', $key);
        self::assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();

        self::assertStringContainsString('Server & Platform Status', $output);
        self::assertStringContainsString('Registered Users', $output);
        self::assertStringContainsString('Repositories', $output);
        self::assertStringContainsString('Active Deployments', $output);
    }

    public function testHostAliasResolvesToStatus(): void
    {
        $key = self::generateKey();
        $admin = new User(
            'admin-' . bin2hex(random_bytes(4)),
            true,
            new \DateTimeImmutable(),
        );
        $admin->addPublicKey(SshKey::parse($key), new \DateTimeImmutable());
        $this->userStore->save($admin);

        $tester = $this->dispatch('host status', $key);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Server & Platform Status', $tester->getDisplay());
    }

    public function testServerSettingsCanBeInspectedAndMutated(): void
    {
        $key = self::generateKey();
        $admin = new User(
            'admin-' . bin2hex(random_bytes(4)),
            true,
            new \DateTimeImmutable(),
        );
        $admin->addPublicKey(SshKey::parse($key), new \DateTimeImmutable());
        $this->userStore->save($admin);

        // Read allow-signup
        $tester = $this->dispatch('server settings allow-signup', $key);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('true', $tester->getDisplay());

        // Update allow-signup
        $tester = $this->dispatch('server settings allow-signup false', $key);
        self::assertSame(0, $tester->getStatusCode());
        self::assertFalse($this->serverPolicy->allowSignup());

        // Reset
        $this->serverPolicy->setAllowSignup(true);
    }

    public function testSettingsTransparentPromotion(): void
    {
        $key = self::generateKey();
        $admin = new User(
            'admin-' . bin2hex(random_bytes(4)),
            true,
            new \DateTimeImmutable(),
        );
        $admin->addPublicKey(SshKey::parse($key), new \DateTimeImmutable());
        $this->userStore->save($admin);

        // `settings allow-keyless` promoted to `server settings allow-keyless`
        $tester = $this->dispatch('settings allow-keyless', $key);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('true', $tester->getDisplay());
    }

    public function testServerUpdateOutputsScript(): void
    {
        $key = self::generateKey();
        $admin = new User(
            'admin-' . bin2hex(random_bytes(4)),
            true,
            new \DateTimeImmutable(),
        );
        $admin->addPublicKey(SshKey::parse($key), new \DateTimeImmutable());
        $this->userStore->save($admin);

        $tester = $this->dispatch('server update', $key);
        self::assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();

        self::assertStringContainsString('#!/usr/bin/env bash', $output);
        self::assertStringContainsString('docker compose build', $output);
        self::assertStringContainsString('doctrine:migrations:migrate', $output);
    }

    public function testServerInstallAliasOutputsScript(): void
    {
        $key = self::generateKey();
        $tester = $this->dispatch('server install --bash -y', $key);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('#!/usr/bin/env bash', $tester->getDisplay());
        self::assertStringContainsString('Gickers.sh', $tester->getDisplay());
    }
}

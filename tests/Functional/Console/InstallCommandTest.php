<?php

declare(strict_types=1);

namespace App\Tests\Functional\Console;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class InstallCommandTest extends KernelTestCase
{
    private Application $application;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->application = new Application(self::$kernel);
    }

    public function testInstallBashOutputsScript(): void
    {
        $command = $this->application->find('install');
        $tester = new CommandTester($command);

        $tester->execute(['--bash' => true, '--domain' => 'vps.mygit.org', '--ssh-port' => 2223]);

        self::assertSame(0, $tester->getStatusCode());
        $out = $tester->getDisplay();

        self::assertStringContainsString('#!/usr/bin/env bash', $out);
        self::assertStringContainsString('vps.mygit.org', $out);
        self::assertStringContainsString('SSH_PORT="2223"', $out);
        self::assertStringContainsString('gum_choose', $out);
        self::assertStringContainsString('gum_input', $out);
    }

    public function testInstallPowerShellOutputsScript(): void
    {
        $command = $this->application->find('install');
        $tester = new CommandTester($command);

        $tester->execute(['--ps1' => true, '--domain' => 'win.mygit.org']);

        self::assertSame(0, $tester->getStatusCode());
        $out = $tester->getDisplay();

        self::assertStringContainsString('win.mygit.org', $out);
        self::assertStringContainsString('Gum-Choose', $out);
        self::assertStringContainsString('Gum-Input', $out);
    }

    public function testDeployAliasResolvesToInstall(): void
    {
        $command = $this->application->find('deploy');
        $tester = new CommandTester($command);

        $tester->execute(['--bash' => true]);

        self::assertSame(0, $tester->getStatusCode());
        $out = $tester->getDisplay();

        self::assertStringContainsString('gickers.sh — One-Shot Production Installer', $out);
    }
}

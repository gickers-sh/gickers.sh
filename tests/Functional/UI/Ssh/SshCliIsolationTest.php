<?php

declare(strict_types=1);

namespace App\Tests\Functional\UI\Ssh;

use App\UI\Ssh\SshCliApplication;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SshCliIsolationTest extends KernelTestCase
{
    public function testConsoleListDoesNotContainSshSubcommands(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        $commands = array_keys($application->all());

        foreach ($commands as $cmd) {
            $this->assertFalse(
                str_starts_with($cmd, 'repo:') || str_starts_with($cmd, 'user:') || str_starts_with($cmd, 'token:'),
                sprintf('Command "%s" from SSH CLI leaked into bin/console', $cmd)
            );
        }

        // soft:ssh:dispatch must be exposed in console
        $this->assertArrayHasKey('soft:ssh:dispatch', $application->all());
    }

    public function testSshCliApplicationDoesNotContainKernelCommands(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var SshCliApplication $sshApp */
        $sshApp = $container->get(SshCliApplication::class);

        $commands = array_keys($sshApp->all());

        foreach ($commands as $cmd) {
            $this->assertFalse(
                str_starts_with($cmd, 'soft:') || str_starts_with($cmd, 'doctrine:') || str_starts_with($cmd, 'cache:'),
                sprintf('Kernel command "%s" leaked into SshCliApplication', $cmd)
            );
        }

        // Must contain repo:create, etc.
        $this->assertArrayHasKey('repo:create', $sshApp->all());
    }
}

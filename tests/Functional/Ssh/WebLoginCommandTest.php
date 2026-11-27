<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ssh;

use App\Identity\Application\AddPublicKey;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Identity\Infrastructure\WebAuth\WebSessionTokens;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\UI\Ssh\Command\Web\WebLoginCommand;
use App\UI\Ssh\SshSession;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class WebLoginCommandTest extends KernelTestCase
{
    private const USER_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIG5Wc48m9aH6z9ZJd51QZ6F7t7Vw8oW1/wQ3zB9yK4b2 test@gickers.sh';

    private User $user;
    private SshSession $session;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->user = new User('loginuser-'.bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $container->get(UserStore::class)->save($this->user);

        $addKey = $container->get(AddPublicKey::class);
        $addKey($this->user, $this->user->username(), self::USER_KEY);

        /** @var SshSession $session */
        $session = $container->get('test.ssh_session');
        $this->session = $session;
        $this->session->authenticate($this->user, 'SHA256:fingerprint');
    }

    public function testWebLoginOutputsMagicLinkAndPrompt(): void
    {
        $tester = $this->dispatch('web login', self::USER_KEY);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();

        $this->assertStringContainsString('Open this link in your browser to sign in (valid for 10 minutes):', $output);
        $this->assertStringContainsString('/login/', $output);
        $this->assertStringContainsString("\033]8;;", $output, 'Must contain OSC 8 hyperlink');
        $this->assertStringContainsString('Press [Enter] to copy to clipboard (any other key to cancel)...', $output);
    }

    public function testWebLoginCopiesToClipboardOnEnter(): void
    {
        $container = static::getContainer();
        $tokens = $container->get(WebSessionTokens::class);
        $settings = $container->get(ServerSettings::class);

        $command = new WebLoginCommand(
            $this->session,
            $tokens,
            $settings,
            'test',
        );

        $tester = new CommandTester($command);
        $tester->setInputs(["\n"]);
        $statusCode = $tester->execute([]);

        $this->assertSame(0, $statusCode);
        $output = $tester->getDisplay();

        $this->assertStringContainsString("\033]52;c;", $output, 'Must contain OSC 52 sequence on Enter');
        $this->assertStringContainsString('Copied to clipboard!', $output);
        $this->assertStringContainsString('web logout', $output);
    }

    public function testWebLoginCancelsOnAnyOtherKey(): void
    {
        $container = static::getContainer();
        $tokens = $container->get(WebSessionTokens::class);
        $settings = $container->get(ServerSettings::class);

        $command = new WebLoginCommand(
            $this->session,
            $tokens,
            $settings,
            'test',
        );

        $tester = new CommandTester($command);
        $tester->setInputs(["q\n"]);
        $statusCode = $tester->execute([]);

        $this->assertSame(0, $statusCode);
        $output = $tester->getDisplay();

        $this->assertStringNotContainsString("\033]52;c;", $output, 'Must not emit OSC 52 sequence when cancelled');
        $this->assertStringContainsString('Cancelled.', $output);
        $this->assertStringNotContainsString('web logout', $output);
    }

    public function testWebLoginRequiresAuthenticatedUser(): void
    {
        $this->session->reset();

        $container = static::getContainer();
        $tokens = $container->get(WebSessionTokens::class);
        $settings = $container->get(ServerSettings::class);

        $command = new WebLoginCommand(
            $this->session,
            $tokens,
            $settings,
        );

        $tester = new CommandTester($command);
        $this->expectException(AccessDenied::class);
        $this->expectExceptionMessage('unauthorized');

        $tester->execute([]);
    }

    public function testWebLoginRequiresHttpPublicUrl(): void
    {
        $container = static::getContainer();
        $tokens = $container->get(WebSessionTokens::class);

        $emptySettings = new ServerSettings(
            name: 'test',
            dataPath: sys_get_temp_dir(),
            sshListenAddr: '127.0.0.1:2223',
            sshPublicUrl: 'ssh://localhost:2223',
            httpListenAddr: '127.0.0.1:23232',
            httpPublicUrl: '',
            lfsEnabled: false,
            anonAccess: 'read-only',
            allowKeyless: true,
            initialAdminKeys: '',
        );

        $command = new WebLoginCommand(
            $this->session,
            $tokens,
            $emptySettings,
        );

        $tester = new CommandTester($command);
        $this->expectException(AccessDenied::class);
        $this->expectExceptionMessage('web login is unavailable');

        $tester->execute([]);
    }

    private function dispatch(string $commandLine, ?string $key = null): CommandTester
    {
        self::assertNotNull(self::$kernel);

        $params = ['original-command' => $commandLine];
        if (null !== $key) {
            $params['--key'] = $key;
        }

        $tester = new CommandTester((new Application(self::$kernel))->find('soft:ssh:dispatch'));
        $tester->execute($params, ['capture_stderr_separately' => true]);

        return $tester;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ssh;

use App\Access\Application\ServerPolicy;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Domain\Git\GitRepository;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Tests\GitTestHelper;
use App\UI\Ssh\SshSession;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AboutCommandTest extends KernelTestCase
{
    private const ABOUT_README = <<<'MD'
# codes.sh

Welcome to **codes.sh** - the self-hostable Git server for the command line.

- Fast
- Modern TUI
- Secure SSH and HTTP
MD;

    private User $admin;
    private SshSession $session;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $policy = $container->get(ServerPolicy::class);
        $policy->setAllowKeyless(true);

        $this->admin = new User('admin-'.bin2hex(random_bytes(3)), true, new \DateTimeImmutable());
        $container->get(UserStore::class)->save($this->admin);

        $session = $container->get('test.ssh_session');
        self::assertInstanceOf(SshSession::class, $session);
        $this->session = $session;
        $this->session->authenticate($this->admin, 'SHA256:fingerprint');
    }

    protected function tearDown(): void
    {
        try {
            $container = static::getContainer();
            $storage = $container->get(BareRepositoryStorage::class);
            $name = RepoName::fromInput('.soft-serve');
            if ($storage->exists($name)) {
                $storage->delete($name);
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    private function dispatch(string $commandLine): CommandTester
    {
        self::assertNotNull(self::$kernel);

        $tester = new CommandTester((new Application(self::$kernel))->find('soft:ssh:dispatch'));
        $tester->execute(['original-command' => $commandLine], ['capture_stderr_separately' => true]);

        return $tester;
    }

    private function createSoftServeRepo(string $readmeContent = self::ABOUT_README): GitRepository
    {
        $container = static::getContainer();
        $name = RepoName::fromInput('.soft-serve');
        $gitRepo = $container->get(BareRepositoryStorage::class)->init($name, 'main');

        $repo = new Repo($name, $this->admin, 'Soft Serve Config', 'Server configuration and about', false, false, false, null, new \DateTimeImmutable());
        $container->get(RepoStore::class)->save($repo);

        GitTestHelper::writeFile($gitRepo, 'main', 'README.md', $readmeContent, 'initial commit');

        return $gitRepo;
    }

    public function testAboutWhenNoSoftServeRepoExists(): void
    {
        $tester = $this->dispatch('about');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('No readme found.', $display);
        self::assertStringContainsString('Create a `.soft-serve` repository and add a README.md file to display readme.', $display);
    }

    public function testAboutRendersSoftServeReadmeWithTermstrap(): void
    {
        $this->createSoftServeRepo();

        $tester = $this->dispatch('about');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();

        // Must contain rendered content
        self::assertStringContainsString('codes.sh', $display);
        self::assertStringContainsString('self-hostable Git server', $display);
        self::assertStringContainsString('Fast', $display);

        // Termstrap truecolor / ANSI styling codes should be present (rendered, not raw markdown)
        self::assertStringContainsString("\e[", $display, 'Output must contain ANSI escape codes from Termstrap renderer');
    }

    public function testAboutWithCustomWidth(): void
    {
        $this->createSoftServeRepo();

        $tester = $this->dispatch('about --width 60');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('codes.sh', $display);
        self::assertStringContainsString("\e[", $display);
    }

    public function testAboutHelp(): void
    {
        $tester = $this->dispatch('help about');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('Displays the server about / README document', $display);
        self::assertStringContainsString('about [flags]', $display);
    }

    public function testAboutAutoDetectsWidthOnEnterWithCpr(): void
    {
        $this->createSoftServeRepo();

        self::assertNotNull(self::$kernel);

        $tester = new CommandTester((new Application(self::$kernel))->find('soft:ssh:dispatch'));
        // Simulate terminal emulator CPR response \e[1;135R followed by \n from [Enter]
        $tester->setInputs(["\x1b[1;135R"]);
        $tester->execute(['original-command' => 'about'], ['capture_stderr_separately' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('codes.sh', $display);
        self::assertStringContainsString('Press [Enter] to view...', $display);
    }
}

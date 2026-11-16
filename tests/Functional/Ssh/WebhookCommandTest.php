<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ssh;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\UI\Ssh\SshSession;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class WebhookCommandTest extends KernelTestCase
{
    private User $owner;
    private Repo $repo;
    private SshSession $session;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $userStore = $container->get(UserStore::class);
        $this->owner = new User('whsshuser' . uniqid(), true, new \DateTimeImmutable());
        $userStore->save($this->owner);

        /** @var SshSession $session */
        $session = $container->get('test.ssh_session');
        $this->session = $session;
        $this->session->authenticate($this->owner, 'SHA256:fingerprint');

        $storage = $container->get(BareRepositoryStorage::class);
        $repoName = RepoName::fromInput('wh-ssh-' . uniqid());
        $storage->init($repoName);

        $this->repo = new Repo($repoName, $this->owner, 'proj', 'desc', false, false, false, null, new \DateTimeImmutable());
        $container->get(RepoStore::class)->save($this->repo);
    }

    public function testWebhookLifecycleCommands(): void
    {
        $container = static::getContainer();

        // 1. List when empty
        $listCmd = $container->get('test.webhook_list_command');
        $tester = new CommandTester($listCmd);
        $tester->execute(['repository' => $this->repo->nameString()]);
        $this->assertStringContainsString('No webhooks found.', $tester->getDisplay());

        // 2. Create
        $createCmd = $container->get('test.webhook_create_command');
        $tester = new CommandTester($createCmd);
        $tester->execute([
            'repository' => $this->repo->nameString(),
            'url' => 'https://example.com/hook',
            '--secret' => 'whsecret',
            '--events' => 'push,branch_tag_create',
        ]);
        $this->assertStringContainsString(sprintf('Created webhook 1 for %s', $this->repo->nameString()), $tester->getDisplay());

        // 2b. Create inactive webhook with --active=false
        $tester->execute([
            'repository' => $this->repo->nameString(),
            'url' => 'https://example.com/inactive-hook',
            '--active' => 'false',
        ]);
        $this->assertStringContainsString(sprintf('Created webhook 2 for %s', $this->repo->nameString()), $tester->getDisplay());

        // 3. List
        $tester = new CommandTester($listCmd);
        $tester->execute(['repository' => $this->repo->nameString()]);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('#1     https://example.com/hook                  active', $display);
        $this->assertStringContainsString('#2     https://example.com/inactive-hook         inactive', $display);

        // 4. Delete
        $delCmd = $container->get('test.webhook_delete_command');
        $tester = new CommandTester($delCmd);
        $tester->execute([
            'repository' => $this->repo->nameString(),
            'id' => '1',
        ]);
        $this->assertStringContainsString('Deleted webhook 1', $tester->getDisplay());
        $tester->execute([
            'repository' => $this->repo->nameString(),
            'id' => '2',
        ]);
        $this->assertStringContainsString('Deleted webhook 2', $tester->getDisplay());

        // 5. List after delete
        $tester = new CommandTester($listCmd);
        $tester->execute(['repository' => $this->repo->nameString()]);
        $this->assertStringContainsString('No webhooks found.', $tester->getDisplay());
    }
}

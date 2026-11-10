<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ssh;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Application\RepoStatsService;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Tests\GitTestHelper;
use App\UI\Ssh\SshSession;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `repo info` surfaces usage metrics: clone/fetch counters and on-disk size.
 */
final class RepoInfoStatsTest extends KernelTestCase
{
    public function testInfoShowsClonesFetchesAndDiskUsage(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $owner = new User('infoowner-'.bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $container->get(UserStore::class)->save($owner);

        $session = $container->get('test.ssh_session');
        self::assertInstanceOf(SshSession::class, $session);
        $session->authenticate($owner, 'SHA256:fingerprint');

        $storage = $container->get(BareRepositoryStorage::class);
        $name = RepoName::fromInput('info-'.bin2hex(random_bytes(3)));
        $gitRepo = $storage->init($name, 'main');
        GitTestHelper::writeFile($gitRepo, 'main', 'README.md', "# demo\n", 'init');

        $repo = new Repo($name, $owner, 'proj', 'desc', false, false, false, null, new \DateTimeImmutable());
        $container->get(RepoStore::class)->save($repo);

        $stats = $container->get('test.repo_stats_service');
        self::assertInstanceOf(RepoStatsService::class, $stats);
        $stats->recordClone($name);
        $stats->recordClone($name);
        $stats->recordFetch($name);
        $container->get('doctrine')->getManager()->clear();

        $tester = new CommandTester($container->get('test.repo_info_command'));
        $tester->execute(['repository' => $name->value]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('Clones: 2', $display);
        self::assertStringContainsString('Fetches: 1', $display);
        self::assertMatchesRegularExpression('/Disk Usage: \d/', $display);
    }
}

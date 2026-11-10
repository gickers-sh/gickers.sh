<?php

declare(strict_types=1);

namespace App\Tests\Functional\UI\Http;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Shared\Infrastructure\Git\PktLine;
use App\Tests\GitTestHelper;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end clone/fetch usage counting: a successful `git-upload-pack` with no
 * `have` lines counts as a clone, one with `have` lines counts as a fetch.
 */
final class GitUsageCountingTest extends KernelTestCase
{
    private RepoName $repoName;
    private string $repoPath;
    private string $sha;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $owner = new User('usage-'.bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $container->get(UserStore::class)->save($owner);

        $storage = $container->get(BareRepositoryStorage::class);
        $this->repoName = RepoName::fromInput('usage-'.bin2hex(random_bytes(3)));
        $gitRepo = $storage->init($this->repoName, 'main');
        $this->sha = GitTestHelper::writeFile($gitRepo, 'main', 'README.md', "# demo\n", 'init');
        $this->repoPath = $gitRepo->path;

        $repo = new Repo($this->repoName, $owner, 'proj', 'desc', false, false, false, null, new \DateTimeImmutable());
        $container->get(RepoStore::class)->save($repo);
    }

    private function runUploadPack(string $body): void
    {
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $body);
        rewind($stream);

        $process = static::getContainer()->get('test.git_service_process');
        ob_start();
        try {
            $process->streamRpc($this->repoPath, 'git-upload-pack', $stream, false, null, null, $this->repoName->value);
        } finally {
            ob_end_clean();
            fclose($stream);
        }
    }

    private function counts(): Repo
    {
        static::getContainer()->get('doctrine')->getManager()->clear();
        $repo = static::getContainer()->get(RepoStore::class)->byName($this->repoName);
        self::assertNotNull($repo);

        return $repo;
    }

    public function testUploadPackWithoutHavesCountsAsClone(): void
    {
        $caps = 'multi_ack_detailed side-band-64k thin-pack ofs-delta agent=git/test';
        $body = PktLine::encode(sprintf("want %s %s\n", $this->sha, $caps))
            .PktLine::flush()
            .PktLine::encode("done\n");

        $this->runUploadPack($body);

        $repo = $this->counts();
        self::assertSame(1, $repo->cloneCount());
        self::assertSame(0, $repo->fetchCount());
    }

    public function testUploadPackWithHavesCountsAsFetch(): void
    {
        $caps = 'multi_ack_detailed side-band-64k thin-pack ofs-delta agent=git/test';
        $body = PktLine::encode(sprintf("want %s %s\n", $this->sha, $caps))
            .PktLine::flush()
            .PktLine::encode(sprintf("have %s\n", $this->sha))
            .PktLine::encode("done\n");

        $this->runUploadPack($body);

        $repo = $this->counts();
        self::assertSame(0, $repo->cloneCount());
        self::assertSame(1, $repo->fetchCount());
    }
}

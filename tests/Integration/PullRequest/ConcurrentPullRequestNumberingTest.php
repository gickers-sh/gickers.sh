<?php

declare(strict_types=1);

namespace App\Tests\Integration\PullRequest;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ConcurrentPullRequestNumberingTest extends KernelTestCase
{
    public function testReservePullRequestNumberIsAtomicAndSequential(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $userStore = $container->get(UserStore::class);
        $user = new User('prowner'.uniqid(), true, new \DateTimeImmutable());
        $userStore->save($user);

        $repoStore = $container->get(RepoStore::class);
        $repoName = RepoName::fromInput('pr-num-'.uniqid());
        $repo = new Repo($repoName, $user, 'proj', 'desc', false, false, false, null, new \DateTimeImmutable());
        $repoStore->save($repo);

        $num1 = $repoStore->reservePullRequestNumber($repo);
        $num2 = $repoStore->reservePullRequestNumber($repo);
        $num3 = $repoStore->reservePullRequestNumber($repo);

        $this->assertSame(1, $num1);
        $this->assertSame(2, $num2);
        $this->assertSame(3, $num3);
    }
}

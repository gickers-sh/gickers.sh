<?php

declare(strict_types=1);

namespace App\Tests\Integration\Access;

use App\Access\Application\AccessGuard;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Domain\CollaboratorStore;
use App\Repo\Domain\Model\Collaborator;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\NotFound;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AccessGuardTest extends KernelTestCase
{
    private AccessGuard $guard;
    private UserStore $userStore;
    private RepoStore $repoStore;
    private CollaboratorStore $collabStore;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->guard = $container->get('test.access_guard');
        $this->userStore = $container->get(UserStore::class);
        $this->repoStore = $container->get(RepoStore::class);
        $this->collabStore = $container->get(CollaboratorStore::class);
    }

    public function testMissingRepoDenyReadForAnonymous(): void
    {
        $missingName = RepoName::fromInput('nonexistent-repo');

        $this->expectException(AccessDenied::class);
        $this->expectExceptionMessage('unauthorized');

        $this->guard->assertRepo(null, $missingName, AccessLevel::ReadOnlyAccess);
    }

    public function testMissingRepoDenyReadForAuthenticated(): void
    {
        $now = new \DateTimeImmutable();
        $user = new User('bob', false, $now);
        $this->userStore->save($user);

        $missingName = RepoName::fromInput('nonexistent-repo');

        $this->expectException(NotFound::class);
        $this->expectExceptionMessage('repository not found');

        $this->guard->assertRepo($user, $missingName, AccessLevel::ReadOnlyAccess);
    }

    public function testPrivateRepoDenyReadForAnonymous(): void
    {
        $now = new \DateTimeImmutable();
        $owner = new User('alice', false, $now);
        $this->userStore->save($owner);

        $repo = new Repo(
            RepoName::fromInput('secret-project'),
            $owner,
            'Secret',
            'Private repository',
            true,
            false,
            false,
            null,
            $now
        );
        $this->repoStore->save($repo);

        $this->expectException(AccessDenied::class);
        $this->expectExceptionMessage('unauthorized');

        $this->guard->assertRepo(null, $repo->name(), AccessLevel::ReadOnlyAccess);
    }

    public function testPrivateRepoDenyReadForAuthenticatedNonCollaborator(): void
    {
        $now = new \DateTimeImmutable();
        $owner = new User('alice2', false, $now);
        $otherUser = new User('charlie', false, $now);
        $this->userStore->save($owner);
        $this->userStore->save($otherUser);

        $repo = new Repo(
            RepoName::fromInput('secret-project2'),
            $owner,
            'Secret',
            'Private repository',
            true,
            false,
            false,
            null,
            $now
        );
        $this->repoStore->save($repo);

        $this->expectException(NotFound::class);
        $this->expectExceptionMessage('repository not found');

        $this->guard->assertRepo($otherUser, $repo->name(), AccessLevel::ReadOnlyAccess);
    }

    public function testReadableRepoInsufficientAccessThrowsAccessDenied(): void
    {
        $now = new \DateTimeImmutable();
        $owner = new User('alice3', false, $now);
        $collab = new User('david', false, $now);
        $this->userStore->save($owner);
        $this->userStore->save($collab);

        $repo = new Repo(
            RepoName::fromInput('shared-project'),
            $owner,
            'Shared',
            'Private repository',
            true,
            false,
            false,
            null,
            $now
        );
        $this->repoStore->save($repo);

        $collabEntity = new Collaborator($repo, $collab, AccessLevel::ReadOnlyAccess, $now);
        $this->collabStore->save($collabEntity);

        // Readable (ReadOnlyAccess >= ReadOnlyAccess), but caller needs ReadWriteAccess
        $this->expectException(AccessDenied::class);
        $this->expectExceptionMessage('unauthorized');

        $this->guard->assertRepo($collab, $repo->name(), AccessLevel::ReadWriteAccess);
    }

    public function testAssertServerAdmin(): void
    {
        $now = new \DateTimeImmutable();
        $regularUser = new User('regular', false, $now);
        $adminUser = new User('superadmin', true, $now);
        $this->userStore->save($regularUser);
        $this->userStore->save($adminUser);

        // Anonymous throws
        try {
            $this->guard->assertServerAdmin(null);
            $this->fail('Expected AccessDenied for anonymous');
        } catch (AccessDenied $e) {
            $this->assertSame('unauthorized', $e->getMessage());
        }

        // Regular user throws
        try {
            $this->guard->assertServerAdmin($regularUser);
            $this->fail('Expected AccessDenied for non-admin');
        } catch (AccessDenied $e) {
            $this->assertSame('unauthorized', $e->getMessage());
        }

        // Admin passes
        $this->guard->assertServerAdmin($adminUser);
        $this->assertTrue(true);
    }
}

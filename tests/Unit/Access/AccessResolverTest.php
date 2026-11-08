<?php

declare(strict_types=1);

namespace App\Tests\Unit\Access;

use App\Access\Application\AccessResolver;
use App\Access\Application\ServerPolicy;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\CollaboratorStore;
use App\Repo\Domain\Model\Collaborator;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoDirectory;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccessResolverTest extends TestCase
{
    private function createUser(int $id, string $username, bool $isAdmin = false): User
    {
        $user = new User($username, $isAdmin, new \DateTimeImmutable('2026-01-01 00:00:00'));
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);

        return $user;
    }

    private function createRepo(int $id, string $name, User $owner, bool $isPrivate = false): Repo
    {
        $repo = new Repo(
            RepoName::fromInput($name),
            $owner,
            'Project '.$name,
            'Description',
            $isPrivate,
            false,
            false,
            null,
            new \DateTimeImmutable('2026-01-01 00:00:00')
        );
        $ref = new \ReflectionProperty(Repo::class, 'id');
        $ref->setValue($repo, $id);

        return $repo;
    }

    #[DataProvider('decisionTableProvider')]
    public function testDecisionTable(
        AccessLevel $anonLevel,
        bool $userPresent,
        bool $userIsAdmin,
        bool $repoPresent,
        bool $orphanDirectory,
        bool $isOwner,
        ?AccessLevel $collabLevel,
        bool $isPrivate,
        AccessLevel $expectedLevel,
    ): void {
        $owner = $this->createUser(1, 'owner', false);
        $testUser = null;
        if ($userPresent) {
            $testUser = $isOwner ? $owner : $this->createUser(2, 'testuser', $userIsAdmin);
        }

        $repoName = RepoName::fromInput('my-repo');
        $repo = null;
        if ($repoPresent) {
            $repo = $this->createRepo(10, 'my-repo', $owner, $isPrivate);
        }
        $settings = new \App\Shared\Infrastructure\Config\ServerSettings(
            name: 'test',
            dataPath: '/tmp',
            sshListenAddr: ':22',
            sshPublicUrl: 'ssh://localhost',
            httpListenAddr: ':80',
            httpPublicUrl: 'http://localhost',
            lfsEnabled: false,
            anonAccess: $anonLevel->toString(),
            allowKeyless: true,
            initialAdminKeys: '',
        );
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $em->method('find')->willReturn(null);
        $clock = new \Symfony\Component\Clock\MockClock();
        $policy = new ServerPolicy($em, $settings, $clock);

        $repoStore = $this->createMock(RepoStore::class);
        $repoStore->method('byName')->with($repoName)->willReturn($repo);

        $collabStore = $this->createMock(CollaboratorStore::class);
        if (null !== $repo && null !== $testUser && null !== $collabLevel) {
            $collaborator = new Collaborator(
                $repo,
                $testUser,
                $collabLevel,
                new \DateTimeImmutable('2026-01-01 00:00:00')
            );
            $collabStore->method('find')->with($repo, $testUser)->willReturn($collaborator);
        } else {
            $collabStore->method('find')->willReturn(null);
        }

        $repoDir = $this->createMock(RepoDirectory::class);
        $repoDir->method('exists')->with($repoName)->willReturn($orphanDirectory);

        $resolver = new AccessResolver($policy, $repoStore, $collabStore, $repoDir);
        $actual = $resolver->levelFor($testUser, $repoName);

        $this->assertSame(
            $expectedLevel,
            $actual,
            sprintf(
                'Failed decision table case: anon=%s, user=%s, admin=%d, repo=%d, orphan=%d, owner=%d, collab=%s, private=%d',
                $anonLevel->toString(),
                $userPresent ? ($isOwner ? 'owner' : ($userIsAdmin ? 'admin' : 'regular')) : 'anon',
                $userIsAdmin ? 1 : 0,
                $repoPresent ? 1 : 0,
                $orphanDirectory ? 1 : 0,
                $isOwner ? 1 : 0,
                $collabLevel?->toString() ?? 'none',
                $isPrivate ? 1 : 0
            )
        );
    }

    /**
     * @return iterable<string, array{
     *     AccessLevel, bool, bool, bool, bool, bool, ?AccessLevel, bool, AccessLevel
     * }>
     */
    public static function decisionTableProvider(): iterable
    {
        // 1. Admin users always get AdminAccess
        yield 'admin on public repo' => [
            AccessLevel::ReadOnlyAccess,
            true,
            true,
            true,
            false,
            false,
            null,
            false,
            AccessLevel::AdminAccess,
        ];
        yield 'admin on private repo' => [
            AccessLevel::ReadOnlyAccess,
            true,
            true,
            true,
            false,
            false,
            null,
            true,
            AccessLevel::AdminAccess,
        ];
        yield 'admin on orphan dir (missing repo row)' => [
            AccessLevel::ReadOnlyAccess,
            true,
            true,
            false,
            true,
            false,
            null,
            false,
            AccessLevel::AdminAccess,
        ];
        yield 'admin on missing repo (no dir)' => [
            AccessLevel::NoAccess,
            true,
            true,
            false,
            false,
            false,
            null,
            false,
            AccessLevel::AdminAccess,
        ];

        // 2. Missing repo row with orphan directory on disk
        yield 'non-admin on orphan dir' => [
            AccessLevel::ReadOnlyAccess,
            true,
            false,
            false,
            true,
            false,
            null,
            false,
            AccessLevel::NoAccess,
        ];
        yield 'anonymous on orphan dir' => [
            AccessLevel::ReadOnlyAccess,
            false,
            false,
            false,
            true,
            false,
            null,
            false,
            AccessLevel::NoAccess,
        ];

        // 3. Missing repo row without orphan directory (creation / baseAccessLevel)
        // Anon = no-access
        yield 'missing repo, anon=no-access, auth user' => [
            AccessLevel::NoAccess,
            true,
            false,
            false,
            false,
            false,
            null,
            false,
            AccessLevel::ReadWriteAccess,
        ];
        yield 'missing repo, anon=no-access, anon user' => [
            AccessLevel::NoAccess,
            false,
            false,
            false,
            false,
            false,
            null,
            false,
            AccessLevel::NoAccess,
        ];
        // Anon = read-only
        yield 'missing repo, anon=read-only, auth user' => [
            AccessLevel::ReadOnlyAccess,
            true,
            false,
            false,
            false,
            false,
            null,
            false,
            AccessLevel::ReadWriteAccess,
        ];
        yield 'missing repo, anon=read-only, anon user' => [
            AccessLevel::ReadOnlyAccess,
            false,
            false,
            false,
            false,
            false,
            null,
            false,
            AccessLevel::ReadOnlyAccess,
        ];
        // Anon = read-write
        yield 'missing repo, anon=read-write, auth user' => [
            AccessLevel::ReadWriteAccess,
            true,
            false,
            false,
            false,
            false,
            null,
            false,
            AccessLevel::ReadWriteAccess,
        ];
        yield 'missing repo, anon=read-write, anon user' => [
            AccessLevel::ReadWriteAccess,
            false,
            false,
            false,
            false,
            false,
            null,
            false,
            AccessLevel::ReadWriteAccess,
        ];
        // Anon = admin-access
        yield 'missing repo, anon=admin-access, auth user' => [
            AccessLevel::AdminAccess,
            true,
            false,
            false,
            false,
            false,
            null,
            false,
            AccessLevel::AdminAccess,
        ];
        yield 'missing repo, anon=admin-access, anon user' => [
            AccessLevel::AdminAccess,
            false,
            false,
            false,
            false,
            false,
            null,
            false,
            AccessLevel::AdminAccess,
        ];

        // 4. Owner branch
        yield 'owner on private repo' => [
            AccessLevel::NoAccess,
            true,
            false,
            true,
            false,
            true,
            null,
            true,
            AccessLevel::AdminAccess,
        ];
        yield 'owner on public repo' => [
            AccessLevel::NoAccess,
            true,
            false,
            true,
            false,
            true,
            null,
            false,
            AccessLevel::AdminAccess,
        ];

        // 5. Collaborator branch
        // Private repo: anon floor does NOT apply
        yield 'collab RO on private repo with anon=RW' => [
            AccessLevel::ReadWriteAccess,
            true,
            false,
            true,
            false,
            false,
            AccessLevel::ReadOnlyAccess,
            true,
            AccessLevel::ReadOnlyAccess,
        ];
        yield 'collab RW on private repo with anon=no-access' => [
            AccessLevel::NoAccess,
            true,
            false,
            true,
            false,
            false,
            AccessLevel::ReadWriteAccess,
            true,
            AccessLevel::ReadWriteAccess,
        ];
        yield 'collab Admin on private repo' => [
            AccessLevel::NoAccess,
            true,
            false,
            true,
            false,
            false,
            AccessLevel::AdminAccess,
            true,
            AccessLevel::AdminAccess,
        ];
        // Public repo: max(anon, collab)
        yield 'collab RO on public repo with anon=no-access' => [
            AccessLevel::NoAccess,
            true,
            false,
            true,
            false,
            false,
            AccessLevel::ReadOnlyAccess,
            false,
            AccessLevel::ReadOnlyAccess,
        ];
        yield 'collab RO on public repo with anon=read-only' => [
            AccessLevel::ReadOnlyAccess,
            true,
            false,
            true,
            false,
            false,
            AccessLevel::ReadOnlyAccess,
            false,
            AccessLevel::ReadOnlyAccess,
        ];
        yield 'collab RO on public repo with anon=read-write' => [
            AccessLevel::ReadWriteAccess,
            true,
            false,
            true,
            false,
            false,
            AccessLevel::ReadOnlyAccess,
            false,
            AccessLevel::ReadWriteAccess,
        ];
        yield 'collab RO on public repo with anon=admin-access' => [
            AccessLevel::AdminAccess,
            true,
            false,
            true,
            false,
            false,
            AccessLevel::ReadOnlyAccess,
            false,
            AccessLevel::AdminAccess,
        ];
        yield 'collab RW on public repo with anon=read-only' => [
            AccessLevel::ReadOnlyAccess,
            true,
            false,
            true,
            false,
            false,
            AccessLevel::ReadWriteAccess,
            false,
            AccessLevel::ReadWriteAccess,
        ];

        // 6. Private repo (non-owner, non-collab)
        yield 'auth non-collab on private repo' => [
            AccessLevel::ReadOnlyAccess,
            true,
            false,
            true,
            false,
            false,
            null,
            true,
            AccessLevel::NoAccess,
        ];
        yield 'anon visitor on private repo' => [
            AccessLevel::ReadOnlyAccess,
            false,
            false,
            true,
            false,
            false,
            null,
            true,
            AccessLevel::NoAccess,
        ];

        // 7. Public repo (non-owner, non-collab): user=max(anon, RO), anon=anon
        // anon = no-access
        yield 'public repo, anon=no-access, auth user' => [
            AccessLevel::NoAccess,
            true,
            false,
            true,
            false,
            false,
            null,
            false,
            AccessLevel::ReadOnlyAccess,
        ];
        yield 'public repo, anon=no-access, anon user' => [
            AccessLevel::NoAccess,
            false,
            false,
            true,
            false,
            false,
            null,
            false,
            AccessLevel::NoAccess,
        ];
        // anon = read-only
        yield 'public repo, anon=read-only, auth user' => [
            AccessLevel::ReadOnlyAccess,
            true,
            false,
            true,
            false,
            false,
            null,
            false,
            AccessLevel::ReadOnlyAccess,
        ];
        yield 'public repo, anon=read-only, anon user' => [
            AccessLevel::ReadOnlyAccess,
            false,
            false,
            true,
            false,
            false,
            null,
            false,
            AccessLevel::ReadOnlyAccess,
        ];
        // anon = read-write
        yield 'public repo, anon=read-write, auth user' => [
            AccessLevel::ReadWriteAccess,
            true,
            false,
            true,
            false,
            false,
            null,
            false,
            AccessLevel::ReadWriteAccess,
        ];
        yield 'public repo, anon=read-write, anon user' => [
            AccessLevel::ReadWriteAccess,
            false,
            false,
            true,
            false,
            false,
            null,
            false,
            AccessLevel::ReadWriteAccess,
        ];
        // anon = admin-access
        yield 'public repo, anon=admin-access, auth user' => [
            AccessLevel::AdminAccess,
            true,
            false,
            true,
            false,
            false,
            null,
            false,
            AccessLevel::AdminAccess,
        ];
        yield 'public repo, anon=admin-access, anon user' => [
            AccessLevel::AdminAccess,
            false,
            false,
            true,
            false,
            false,
            null,
            false,
            AccessLevel::AdminAccess,
        ];
    }
}

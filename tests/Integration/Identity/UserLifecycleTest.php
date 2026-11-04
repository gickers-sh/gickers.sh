<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\CreateUser;
use App\Identity\Application\DeleteUser;
use App\Identity\Application\RenameUser;
use App\Identity\Application\SetAdmin;
use App\Identity\Application\SetPassword;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Domain\Exception\NotFound;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UserLifecycleTest extends KernelTestCase
{
    private UserStore $userStore;
    private RepoStore $repoStore;
    private CreateUser $createUser;
    private DeleteUser $deleteUser;
    private RenameUser $renameUser;
    private SetAdmin $setAdmin;
    private SetPassword $setPassword;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->userStore = $container->get(UserStore::class);
        $this->repoStore = $container->get(RepoStore::class);
        $this->createUser = $container->get('test.create_user');
        $this->deleteUser = $container->get('test.delete_user');
        $this->renameUser = $container->get('test.rename_user');
        $this->setAdmin = $container->get('test.set_admin');
        $this->setPassword = $container->get('test.set_password');
    }

    private function createAdminActor(): User
    {
        $now = new \DateTimeImmutable();
        $admin = new User('superadmin', true, $now);
        $this->userStore->save($admin);

        return $admin;
    }

    public function testCreateUserSuccessAndValidation(): void
    {
        $admin = $this->createAdminActor();

        $user = ($this->createUser)($admin, 'alice', false);
        $this->assertSame('alice', $user->username());
        $this->assertFalse($user->isAdmin());

        $fetched = $this->userStore->byUsername('alice');
        $this->assertNotNull($fetched);
        $this->assertSame($user->id(), $fetched->id());

        // Anonymous or non-admin cannot create
        $this->expectException(AccessDenied::class);
        ($this->createUser)($user, 'bob', false);
    }

    public function testCreateUserDuplicateConflict(): void
    {
        $admin = $this->createAdminActor();
        ($this->createUser)($admin, 'charlie', false);

        $this->expectException(Conflict::class);
        $this->expectExceptionMessage('already exists');
        ($this->createUser)($admin, 'charlie', false);
    }

    public function testCreateUserInvalidUsername(): void
    {
        $admin = $this->createAdminActor();

        $this->expectException(InvalidInput::class);
        ($this->createUser)($admin, '123invalid', false);
    }

    public function testRenameUserSelfAndAdmin(): void
    {
        $admin = $this->createAdminActor();
        $user1 = ($this->createUser)($admin, 'dave', false);
        $user2 = ($this->createUser)($admin, 'eva', false);

        // Self rename
        $renamed = ($this->renameUser)($user1, 'dave', 'david');
        $this->assertSame('david', $renamed->username());
        $this->assertNull($this->userStore->byUsername('dave'));
        $this->assertNotNull($this->userStore->byUsername('david'));

        // Admin renames another user
        $renamedEva = ($this->renameUser)($admin, 'eva', 'evelyn');
        $this->assertSame('evelyn', $renamedEva->username());

        // Non-admin cannot rename someone else
        $this->expectException(AccessDenied::class);
        ($this->renameUser)($renamed, 'evelyn', 'eve');
    }

    public function testRenameUserDuplicateConflict(): void
    {
        $admin = $this->createAdminActor();
        $user1 = ($this->createUser)($admin, 'frank', false);
        ($this->createUser)($admin, 'grace', false);

        $this->expectException(Conflict::class);
        $this->expectExceptionMessage('already exists');
        ($this->renameUser)($user1, 'frank', 'grace');
    }

    public function testDeleteUserWithReposConflict(): void
    {
        $admin = $this->createAdminActor();
        $user = ($this->createUser)($admin, 'heidi', false);

        $repo = new Repo(
            RepoName::fromInput('heidi-repo'),
            $user,
            'Heidi Repo',
            'Repo owned by Heidi',
            false,
            false,
            false,
            null,
            new \DateTimeImmutable()
        );
        $this->repoStore->save($repo);

        $this->expectException(Conflict::class);
        $this->expectExceptionMessage('user owns repositories');
        ($this->deleteUser)($admin, 'heidi');
    }

    public function testDeleteUserSuccess(): void
    {
        $admin = $this->createAdminActor();
        ($this->createUser)($admin, 'ivan', false);
        $this->assertNotNull($this->userStore->byUsername('ivan'));

        ($this->deleteUser)($admin, 'ivan');
        $this->assertNull($this->userStore->byUsername('ivan'));

        // Deleting nonexistent user
        $this->expectException(NotFound::class);
        ($this->deleteUser)($admin, 'ivan');
    }

    public function testSetAdmin(): void
    {
        $admin = $this->createAdminActor();
        $user = ($this->createUser)($admin, 'judy', false);
        $this->assertFalse($user->isAdmin());

        $updated = ($this->setAdmin)($admin, 'judy', true);
        $this->assertTrue($updated->isAdmin());

        // Reload
        $reloaded = $this->userStore->byUsername('judy');
        $this->assertTrue($reloaded->isAdmin());

        // Non-admin cannot set admin
        $regularUser = ($this->createUser)($admin, 'mallory', false);
        $this->expectException(AccessDenied::class);
        ($this->setAdmin)($regularUser, 'judy', false);
    }

    public function testSetPassword(): void
    {
        $admin = $this->createAdminActor();
        $user = ($this->createUser)($admin, 'oscar', false);
        $this->assertNull($user->passwordHash());

        // User sets own password
        ($this->setPassword)($user, 'oscar', 'secret123');

        $reloaded = $this->userStore->byUsername('oscar');
        $this->assertNotNull($reloaded->passwordHash());
        $this->assertTrue(password_verify('secret123', $reloaded->passwordHash()));
    }
}

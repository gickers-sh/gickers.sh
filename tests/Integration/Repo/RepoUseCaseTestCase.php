<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repo;

use App\Access\Application\AccessGuard;
use App\Access\Application\AccessResolver;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Application\AddCollaborator;
use App\Repo\Application\CreateRepo;
use App\Repo\Application\DeleteBranch;
use App\Repo\Application\DeleteRepo;
use App\Repo\Application\DeleteTag;
use App\Repo\Application\ForkRepo;
use App\Repo\Application\ImportRepo;
use App\Repo\Application\Query\RepoQuery;
use App\Repo\Application\RecordPush;
use App\Repo\Application\RemoveCollaborator;
use App\Repo\Application\RenameRepo;
use App\Repo\Application\SetDefaultBranch;
use App\Repo\Application\UpdateRepoSettings;
use App\Repo\Domain\CollaboratorStore;
use App\Repo\Domain\Git\GitRepository;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Repo\Infrastructure\Git\GitRepositoryWriter;
use App\Repo\Infrastructure\Git\HookInstaller;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\Shared\Infrastructure\Security\SsrfGuard;
use App\Tests\GitTestHelper;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Process\Process;

/**
 * Base for Repo use-case integration tests.
 *
 * Database rows live in the test database (rolled back per test by DAMA); bare
 * git repositories live under an isolated temp directory. Use cases are wired by
 * hand from container-backed collaborators (stores, access services) and the
 * temp-dir git infrastructure, with a {@see MockClock} and a
 * {@see RecordingEventDispatcher} for deterministic timestamps and event assertions.
 */
abstract class RepoUseCaseTestCase extends KernelTestCase
{
    protected string $dataPath;
    /** @var list<string> */
    private array $tempDirs = [];

    protected BareRepositoryStorage $storage;
    protected GitRepositoryReader $reader;
    protected GitRepositoryWriter $writer;
    protected HookInstaller $hookInstaller;
    protected RepoStore $repoStore;
    protected CollaboratorStore $collabStore;
    protected UserStore $userStore;
    protected AccessResolver $accessResolver;
    protected AccessGuard $accessGuard;
    protected SsrfGuard $ssrfGuard;
    protected MockClock $clock;
    protected RecordingEventDispatcher $events;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->dataPath = sys_get_temp_dir().'/repo_uc_'.uniqid('', true);
        mkdir($this->dataPath.'/repos', 0755, true);
        $this->tempDirs[] = $this->dataPath;

        $settings = new ServerSettings(
            name: 'test-server',
            dataPath: $this->dataPath,
            sshListenAddr: '127.0.0.1:2222',
            sshPublicUrl: 'ssh://localhost:2222',
            httpListenAddr: '127.0.0.1:23232',
            httpPublicUrl: 'http://localhost:23232',
            lfsEnabled: true,
            anonAccess: 'read-only',
            allowKeyless: false,
            initialAdminKeys: '',
        );

        $this->reader = new GitRepositoryReader();
        $this->storage = new BareRepositoryStorage($settings, $this->reader);
        $this->writer = new GitRepositoryWriter();
        $this->hookInstaller = new HookInstaller($this->dataPath.'/bin/console');

        $this->repoStore = $container->get(RepoStore::class);
        $this->collabStore = $container->get(CollaboratorStore::class);
        $this->userStore = $container->get(UserStore::class);
        $this->accessResolver = $container->get(AccessResolver::class);
        $this->accessGuard = $container->get('test.access_guard');

        $this->ssrfGuard = new SsrfGuard();
        $this->clock = new MockClock(new \DateTimeImmutable('2026-01-01 12:00:00'));
        $this->events = new RecordingEventDispatcher();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (is_dir($dir)) {
                (new Process(['rm', '-rf', $dir]))->run();
            }
        }
        $this->tempDirs = [];
    }

    protected function user(string $name, bool $admin = false): User
    {
        $user = new User($name, $admin, $this->clock->now());
        $this->userStore->save($user);

        return $user;
    }

    protected function repoRow(
        string $name,
        User $owner,
        bool $private = false,
        bool $hidden = false,
        ?Repo $forkOf = null,
    ): Repo {
        $repo = new Repo(
            name: RepoName::fromInput($name),
            owner: $owner,
            projectName: '',
            description: '',
            isPrivate: $private,
            isHidden: $hidden,
            isMirror: false,
            forkOf: $forkOf,
            createdAt: $this->clock->now(),
        );
        $this->repoStore->save($repo);

        return $repo;
    }

    /** Creates the bare repository on disk with one commit on `main`. */
    protected function initRepoOnDisk(string $name): GitRepository
    {
        $repoName = RepoName::fromInput($name);
        $gitRepo = $this->storage->init($repoName, 'main');
        GitTestHelper::writeFile($gitRepo, 'main', 'README.md', "# {$name}\n", 'init', 'Tester', 'tester@gickers.sh');

        return $this->storage->open($repoName);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function singleEvent(string $class): object
    {
        $matches = $this->eventsOf($class);
        self::assertCount(1, $matches, $class.' should be emitted exactly once');

        return $matches[0];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    protected function eventsOf(string $class): array
    {
        return array_values(array_filter($this->events->events, static fn (object $e): bool => $e instanceof $class));
    }

    protected function createRepoUseCase(): CreateRepo
    {
        return new CreateRepo($this->repoStore, $this->accessResolver, $this->storage, $this->hookInstaller, $this->clock, $this->events);
    }

    protected function importRepoUseCase(): ImportRepo
    {
        return new ImportRepo($this->repoStore, $this->accessResolver, $this->storage, $this->hookInstaller, $this->ssrfGuard, $this->clock, $this->events);
    }

    protected function forkRepoUseCase(): ForkRepo
    {
        return new ForkRepo($this->repoStore, $this->accessGuard, $this->accessResolver, $this->storage, $this->hookInstaller, $this->clock, $this->events, ssrfGuard: $this->ssrfGuard);
    }

    protected function deleteRepoUseCase(): DeleteRepo
    {
        return new DeleteRepo($this->repoStore, $this->accessGuard, $this->storage, $this->events);
    }

    protected function renameRepoUseCase(): RenameRepo
    {
        return new RenameRepo($this->repoStore, $this->accessGuard, $this->storage, $this->clock, $this->events);
    }

    protected function updateSettingsUseCase(): UpdateRepoSettings
    {
        return new UpdateRepoSettings($this->repoStore, $this->accessGuard, $this->clock, $this->events);
    }

    protected function addCollaboratorUseCase(): AddCollaborator
    {
        return new AddCollaborator($this->accessGuard, $this->userStore, $this->collabStore, $this->clock, $this->events);
    }

    protected function removeCollaboratorUseCase(): RemoveCollaborator
    {
        return new RemoveCollaborator($this->accessGuard, $this->userStore, $this->collabStore, $this->events);
    }

    protected function deleteBranchUseCase(): DeleteBranch
    {
        return new DeleteBranch($this->accessGuard, $this->storage, $this->reader, $this->writer, $this->events);
    }

    protected function setDefaultBranchUseCase(): SetDefaultBranch
    {
        return new SetDefaultBranch($this->accessGuard, $this->storage, $this->reader);
    }

    protected function deleteTagUseCase(): DeleteTag
    {
        return new DeleteTag($this->accessGuard, $this->storage, $this->reader, $this->writer, $this->events);
    }

    protected function recordPushUseCase(): RecordPush
    {
        return new RecordPush($this->repoStore, $this->clock, $this->events);
    }

    protected function repoQuery(): RepoQuery
    {
        return new RepoQuery($this->repoStore, $this->collabStore, $this->accessResolver, $this->accessGuard);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ssh;

use App\Access\Application\ServerPolicy;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Domain\Git\GitRepository;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Domain\Exception\NotFound;
use App\Tests\GitTestHelper;
use App\UI\Ssh\Command\Repo\RepoGrepCommand;
use App\UI\Ssh\Help\CommandCatalog;
use App\UI\Ssh\SshCommandResolver;
use App\UI\Ssh\SshSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/**
 * Functional coverage for the read-only SSH inspection commands `repo grep`
 * (alias `repo search`), `repo readme` and `repo diff`, run against a seeded
 * bare repository:
 *
 *   main    README.md, src/app.txt (two revisions: $before then $after)
 *   feature notes.txt                (root commit, no README)
 *   docs    README.md                (root commit, different README)
 */
final class RepoGrepReadmeDiffCommandTest extends KernelTestCase
{
    private const MAIN_README = "# Search Demo\n\nWelcome to the <b>demo</b> repository & friends.\n";
    private const DOCS_README = "# Docs branch\n";

    private User $owner;
    private SshSession $session;
    private RepoName $repoName;
    private GitRepository $gitRepo;
    private string $before;
    private string $after;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->owner = new User('srchowner-'.bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $container->get(UserStore::class)->save($this->owner);

        $session = $container->get('test.ssh_session');
        self::assertInstanceOf(SshSession::class, $session);
        $this->session = $session;
        $this->session->authenticate($this->owner, 'SHA256:fingerprint');

        [$this->repoName, $this->gitRepo] = $this->createRepo('srch', false);

        GitTestHelper::writeFile($this->gitRepo, 'main', 'README.md', self::MAIN_README, 'add readme');
        $this->before = GitTestHelper::writeFile($this->gitRepo, 'main', 'src/app.txt', "alpha line\nneedle in a haystack\nomega line\n", 'add app');
        $this->after = GitTestHelper::writeFile($this->gitRepo, 'main', 'src/app.txt', "alpha line\nneedle in a better haystack\nomega line\n- dashed bullet\n", 'improve app');
        GitTestHelper::writeFile($this->gitRepo, 'feature', 'notes.txt', "feature-only marker\n", 'add notes');
        GitTestHelper::writeFile($this->gitRepo, 'docs', 'README.md', self::DOCS_README, 'add docs readme');
    }

    /**
     * @return array{0: RepoName, 1: GitRepository}
     */
    private function createRepo(string $prefix, bool $private): array
    {
        $container = static::getContainer();

        $name = RepoName::fromInput($prefix.'-'.bin2hex(random_bytes(3)));
        $gitRepo = $container->get(BareRepositoryStorage::class)->init($name, 'main');

        $repo = new Repo($name, $this->owner, 'proj', 'desc', $private, false, false, null, new \DateTimeImmutable());
        $container->get(RepoStore::class)->save($repo);

        return [$name, $gitRepo];
    }

    private function tester(string $serviceId): CommandTester
    {
        $command = static::getContainer()->get($serviceId);
        self::assertInstanceOf(Command::class, $command);

        return new CommandTester($command);
    }

    /**
     * Runs a full SSH command line through the dispatcher (anonymous session).
     */
    private function dispatch(string $commandLine): CommandTester
    {
        self::assertNotNull(self::$kernel);

        $tester = new CommandTester((new Application(self::$kernel))->find('soft:ssh:dispatch'));
        $tester->execute(['original-command' => $commandLine], ['capture_stderr_separately' => true]);

        return $tester;
    }

    private function git(string ...$args): string
    {
        $process = new Process(['git', ...$args], $this->gitRepo->path);
        $process->mustRun();

        return $process->getOutput();
    }

    public function testGrepFindsKnownLineInSeededFile(): void
    {
        $tester = $this->tester('test.repo_grep_command');
        $status = $tester->execute(['repository' => $this->repoName->value, 'pattern' => 'needle']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame("HEAD:src/app.txt:2:needle in a better haystack\n", $tester->getDisplay());
    }

    public function testGrepPrintsLinesVerbatimWithoutInterpretingMarkup(): void
    {
        $tester = $this->tester('test.repo_grep_command');
        $tester->execute(['repository' => $this->repoName->value, 'pattern' => 'friends']);

        self::assertSame("HEAD:README.md:3:Welcome to the <b>demo</b> repository & friends.\n", $tester->getDisplay());
    }

    public function testGrepSearchesTheRequestedReference(): void
    {
        $onFeature = $this->tester('test.repo_grep_command');
        $onFeature->execute(['repository' => $this->repoName->value, 'pattern' => 'feature-only', 'reference' => 'feature']);
        self::assertSame("feature:notes.txt:1:feature-only marker\n", $onFeature->getDisplay());

        $onMain = $this->tester('test.repo_grep_command');
        $onMain->execute(['repository' => $this->repoName->value, 'pattern' => 'feature-only']);
        self::assertSame('', $onMain->getDisplay());

        $onOldCommit = $this->tester('test.repo_grep_command');
        $onOldCommit->execute(['repository' => $this->repoName->value, 'pattern' => 'needle', 'reference' => $this->before]);
        self::assertSame(sprintf("%s:src/app.txt:2:needle in a haystack\n", $this->before), $onOldCommit->getDisplay());
    }

    public function testGrepTreatsALeadingDashInThePatternAsText(): void
    {
        $tester = $this->tester('test.repo_grep_command');
        $status = $tester->execute(['repository' => $this->repoName->value, 'pattern' => '- dashed']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame("HEAD:src/app.txt:4:- dashed bullet\n", $tester->getDisplay());
    }

    public function testGrepWithoutMatchPrintsNothingAndSucceeds(): void
    {
        $tester = $this->tester('test.repo_grep_command');
        $status = $tester->execute(['repository' => $this->repoName->value, 'pattern' => 'absent-from-every-file']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('', $tester->getDisplay());
    }

    public function testGrepOnUnknownReferenceIsNotFound(): void
    {
        $tester = $this->tester('test.repo_grep_command');

        $this->expectException(NotFound::class);
        $this->expectExceptionMessage('reference not found');
        $tester->execute(['repository' => $this->repoName->value, 'pattern' => 'needle', 'reference' => 'no-such-branch']);
    }

    public function testGrepRejectsOptionLikeReference(): void
    {
        $tester = $this->tester('test.repo_grep_command');

        $this->expectException(InvalidInput::class);
        $tester->execute(['repository' => $this->repoName->value, 'pattern' => 'needle', 'reference' => '--output=/tmp/codes-sh-grep-pwned']);
    }

    public function testReadmePrintsSeededContentVerbatim(): void
    {
        $tester = $this->tester('test.repo_readme_command');
        $status = $tester->execute(['repository' => $this->repoName->value]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(self::MAIN_README, $tester->getDisplay());
    }

    public function testReadmeHonoursTheReferenceArgument(): void
    {
        $tester = $this->tester('test.repo_readme_command');
        $tester->execute(['repository' => $this->repoName->value, 'reference' => 'docs']);

        self::assertSame(self::DOCS_README, $tester->getDisplay());
    }

    public function testReadmeAbsentAtReferencePrintsNothingAndSucceeds(): void
    {
        $tester = $this->tester('test.repo_readme_command');
        $status = $tester->execute(['repository' => $this->repoName->value, 'reference' => 'feature']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('', $tester->getDisplay());
    }

    public function testDiffBetweenTwoCommitsShowsTheChangedLines(): void
    {
        $tester = $this->tester('test.repo_diff_command');
        $status = $tester->execute(['repository' => $this->repoName->value, 'from' => $this->before, 'to' => $this->after]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('diff --git a/src/app.txt b/src/app.txt', $display);
        self::assertStringContainsString("\n-needle in a haystack\n", $display);
        self::assertStringContainsString("\n+needle in a better haystack\n", $display);
        self::assertStringContainsString("\n+- dashed bullet\n", $display);
        // The patch is relayed byte-for-byte from git.
        self::assertSame($this->git('diff', $this->before.'..'.$this->after), $display);
    }

    public function testDiffWithoutSecondReferencePrintsTheChangesIntroducedByTheFirst(): void
    {
        $tester = $this->tester('test.repo_diff_command');
        $status = $tester->execute(['repository' => $this->repoName->value, 'from' => $this->after]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('diff --git a/src/app.txt b/src/app.txt', $display);
        self::assertStringContainsString("\n-needle in a haystack\n", $display);
        self::assertStringContainsString("\n+needle in a better haystack\n", $display);
        self::assertSame($this->git('show', '--patch', '--format=', $this->after), $display);
    }

    public function testDiffBetweenBranchNames(): void
    {
        $tester = $this->tester('test.repo_diff_command');
        $tester->execute(['repository' => $this->repoName->value, 'from' => 'main~1', 'to' => 'main']);

        self::assertStringContainsString("\n+needle in a better haystack\n", $tester->getDisplay());
    }

    public function testDiffOnUnknownReferenceFailsExplicitly(): void
    {
        $tester = $this->tester('test.repo_diff_command');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to get diff');
        $tester->execute(['repository' => $this->repoName->value, 'from' => 'no-such-branch', 'to' => 'main']);
    }

    public function testDiffRejectsOptionLikeReference(): void
    {
        $tester = $this->tester('test.repo_diff_command');

        $this->expectException(InvalidInput::class);
        $tester->execute(['repository' => $this->repoName->value, 'from' => '--output=/tmp/codes-sh-diff-pwned']);
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, string>}>
     */
    public static function commandProvider(): iterable
    {
        yield 'grep' => ['test.repo_grep_command', ['pattern' => 'needle']];
        yield 'readme' => ['test.repo_readme_command', []];
        yield 'diff' => ['test.repo_diff_command', ['from' => 'main']];
    }

    /**
     * @param array<string, string> $arguments
     */
    #[DataProvider('commandProvider')]
    public function testPrivateRepositoryIsNotFoundForNonCollaborators(string $serviceId, array $arguments): void
    {
        [$privateName] = $this->createRepo('priv', true);

        $stranger = new User('stranger-'.bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        static::getContainer()->get(UserStore::class)->save($stranger);
        $this->session->authenticate($stranger, 'SHA256:stranger');

        $tester = $this->tester($serviceId);

        $this->expectException(NotFound::class);
        $this->expectExceptionMessage('repository not found');
        $tester->execute(['repository' => $privateName->value] + $arguments);
    }

    public function testCommandsAreReachableThroughTheDispatcher(): void
    {
        $policy = static::getContainer()->get(ServerPolicy::class);
        self::assertInstanceOf(ServerPolicy::class, $policy);
        $policy->setAllowKeyless(true);
        $policy->setAnonAccess(AccessLevel::ReadOnlyAccess);

        $name = $this->repoName->value;

        $grep = $this->dispatch(sprintf('repo grep %s needle', $name));
        self::assertSame(0, $grep->getStatusCode(), $grep->getErrorOutput());
        self::assertSame("HEAD:src/app.txt:2:needle in a better haystack\n", $grep->getDisplay());

        $readme = $this->dispatch(sprintf('repo readme %s docs', $name));
        self::assertSame(0, $readme->getStatusCode(), $readme->getErrorOutput());
        self::assertSame(self::DOCS_README, $readme->getDisplay());

        $diff = $this->dispatch(sprintf('repo diff %s %s %s', $name, $this->before, $this->after));
        self::assertSame(0, $diff->getStatusCode(), $diff->getErrorOutput());
        self::assertStringContainsString("\n+needle in a better haystack\n", $diff->getDisplay());

        $missing = $this->dispatch(sprintf('repo grep %s needle no-such-branch', $name));
        self::assertSame(1, $missing->getStatusCode());
        self::assertStringContainsString('Error: reference not found', $missing->getErrorOutput());
    }

    public function testSearchAliasResolvesToGrepAndMatchesTheHelpCatalog(): void
    {
        // Built from the real command instead of the container: the public test aliases
        // in test_aliases_search.yaml hide the command types SshCliApplication reads aliases from.
        $resolver = new SshCommandResolver(
            [RepoGrepCommand::sshName()],
            [RepoGrepCommand::sshName() => RepoGrepCommand::sshAliases()],
        );

        $resolved = $resolver->resolve(['repo', 'search', 'demo', 'needle']);
        self::assertNotNull($resolved);
        self::assertSame('repo:grep', $resolved->name);
        self::assertSame(['demo', 'needle'], $resolved->arguments);

        $catalog = new CommandCatalog();
        $grep = $catalog->resolve(['repo', 'grep']);
        self::assertNotNull($grep);
        self::assertSame($grep, $catalog->resolve(['repo', 'search']));
    }
}

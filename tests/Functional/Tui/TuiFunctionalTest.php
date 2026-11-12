<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tui;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Application\CreateRepo;
use App\Repo\Domain\RepoDirectory;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Tests\GitTestHelper;
use App\UI\Console\Command\BrowseCommand;
use App\UI\Console\Command\TuiCommand;
use App\UI\Tui\AsyncLoader;
use App\UI\Tui\Render\TermstrapRenderer;
use App\UI\Tui\TuiAppFactory;
use App\UI\Tui\Widget\DocumentWidget;
use Revolt\EventLoop;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class TuiFunctionalTest extends KernelTestCase
{
    private TuiAppFactory $apps;
    private UserStore $userStore;
    private CreateRepo $createRepo;
    private GitRepositoryReader $gitReader;
    private RepoDirectory $repoDirectory;
    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->apps = $container->get(TuiAppFactory::class);
        $this->userStore = $container->get(UserStore::class);
        $this->createRepo = $container->get(CreateRepo::class);
        $this->gitReader = $container->get(GitRepositoryReader::class);
        $this->repoDirectory = $container->get(RepoDirectory::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (is_dir($dir)) {
                (new Process(['rm', '-rf', $dir]))->run();
            }
        }
        $this->tempDirs = [];
        parent::tearDown();
    }

    private function createTempDir(string $prefix = 'tui_test_'): string
    {
        $path = sys_get_temp_dir() . '/' . $prefix . uniqid('', true);
        mkdir($path, 0755, true);
        $this->tempDirs[] = $path;

        return $path;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function createFixtureRepo(): array
    {
        $dir = $this->createTempDir('fixture_repo_');
        $cmd = sprintf(
            'cd %s && git init -b main && git config user.email "tester@example.com" && git config user.name "Tester"',
            escapeshellarg($dir)
        );
        exec($cmd);

        file_put_contents($dir . '/README.md', "# Demo Repository\n\nWelcome to demo.");
        mkdir($dir . '/src', 0755, true);
        file_put_contents(
            $dir . '/src/Calculator.php',
            "<?php\n\nnamespace App;\n\nclass Calculator\n{\n    public function add(int \$a, int \$b): int\n    {\n        return \$a + \$b;\n    }\n}\n"
        );

        exec(sprintf('cd %s && git add . && git commit -m "initial commit"', escapeshellarg($dir)));
        exec(sprintf('cd %s && git tag v1.0.0', escapeshellarg($dir)));

        $headSha = trim((string) shell_exec(sprintf('cd %s && git rev-parse HEAD', escapeshellarg($dir))));

        return [$dir, $headSha];
    }

    /**
     * Drives TUI ticks until all background git processes settle, returning the
     * concatenated terminal output (including the initial spinner frame and final content).
     */
    private function tickUntilSettled(Tui $tui, VirtualTerminal $vt, int $maxTicks = 120): string
    {
        $tui->tick();
        $out = $vt->consumeOutput();
        $emptyStreak = 0;
        for ($i = 0; $i < $maxTicks; ++$i) {
            usleep(10000);
            $tui->tick();
            $chunk = $vt->consumeOutput();
            if ('' !== $chunk) {
                $out .= $chunk;
                $emptyStreak = 0;
                continue;
            }
            if (++$emptyStreak >= 8) {
                break;
            }
        }

        return $out;
    }

    public function testSelectionPageVisibleTextAndTabSwitching(): void
    {
        $user = new User('alice', false, new \DateTimeImmutable());
        $this->userStore->save($user);

        ($this->createRepo)($user, 'feed-project', false, false, 'A public project', 'feed-project');

        $vt = new VirtualTerminal(80, 24);
        $app = $this->apps->create($vt, $user, true);
        $app->start($app->views()->selection());
        $tui = $app->tui();

        $tui->start();
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('Feed', $output);
        $this->assertStringContainsString('My Repositories', $output);
        $this->assertStringContainsString('About', $output);
        $this->assertStringContainsString('Profile', $output);
        $this->assertStringContainsString('No readme found.', $output);

        // Switch to Feed
        $vt->simulateInput("\t");
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('feed-project', $output);

        // Switch to My Repositories
        $vt->simulateInput("\t");
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('My Repositories', $output);

        // Switch to Profile
        $vt->simulateInput("\t");
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('Username', $output);
        $this->assertStringContainsString('alice', $output);

        // Tab back to About
        $vt->simulateInput("\t");
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('No readme found.', $output);
        // Quit with q
        $vt->simulateInput('q');
        $tui->tick();
        $this->assertFalse($tui->isRunning());

        $tui->stop();
    }

    public function testFeedRefreshKeyPicksUpNewRepos(): void
    {
        $user = new User('refresher', false, new \DateTimeImmutable());
        $this->userStore->save($user);

        ($this->createRepo)($user, 'refresh-early', false, false, 'Loaded first', 'refresh-early');

        $vt = new VirtualTerminal(80, 24);
        $app = $this->apps->create($vt, $user, true);
        $app->start($app->views()->selection());
        $tui = $app->tui();

        $tui->start();
        $this->tickUntilSettled($tui, $vt);

        // Load the Feed tab (caches the repo list).
        $vt->simulateInput("\t");
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('refresh-early', $output);
        $this->assertStringNotContainsString('refresh-late', $output);

        // A repo created after the initial load is not visible yet.
        ($this->createRepo)($user, 'refresh-late', false, false, 'Created later', 'refresh-late');
        $tui->tick();
        $this->assertStringNotContainsString('refresh-late', $vt->consumeOutput());

        // Pressing 'r' reloads the list and flashes feedback.
        $vt->simulateInput('r');
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('refresh-late', $output);
        $this->assertStringContainsString('Refreshed', $output);

        $vt->simulateInput('q');
        $tui->tick();
        $tui->stop();
    }

    public function testSelectionPageHelpModal(): void
    {
        $vt = new VirtualTerminal(80, 24);
        $app = $this->apps->create($vt, null, true);
        $app->start($app->views()->selection());
        $tui = $app->tui();

        $tui->start();
        $initialOutput = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('Soft Serve', $initialOutput);
        $this->assertStringContainsString('About', $initialOutput);

        // Open expanded help grid
        $vt->simulateInput('?');
        $tui->tick();

        $refTui = new \ReflectionClass($tui);
        $propWriter = $refTui->getProperty('screenWriter');
        $writer = $propWriter->getValue($tui);
        $refWriter = new \ReflectionClass($writer);
        $propLines = $refWriter->getProperty('previousLines');
        /** @var list<string> $screenLines */
        $screenLines = $propLines->getValue($writer);
        $screenText = implode("\n", $screenLines);

        // Ensure help is displayed at the bottom
        $this->assertStringContainsString('go to end', $screenText);
        $this->assertStringContainsString('toggle help', $screenText);
        // Ensure header and main selection page content remain visible above the helper
        $this->assertStringContainsString('Soft Serve', $screenText);
        $this->assertStringContainsString('About', $screenText);
        $this->assertStringContainsString('No readme found.', $screenText);

        // Verify the help grid lines appear below the page header lines
        $headerLineIndex = null;
        $helpLineIndex = null;
        foreach ($screenLines as $idx => $line) {
            if (null === $headerLineIndex && str_contains($line, 'Soft Serve')) {
                $headerLineIndex = $idx;
            }
            if (null === $helpLineIndex && str_contains($line, 'toggle help')) {
                $helpLineIndex = $idx;
            }
        }
        $this->assertNotNull($headerLineIndex);
        $this->assertNotNull($helpLineIndex);
        $this->assertGreaterThan($headerLineIndex, $helpLineIndex);

        // Toggle help off with '?'
        $vt->simulateInput('?');
        $tui->tick();
        $screenLinesClosed = $propLines->getValue($writer);
        $screenTextClosed = implode("\n", $screenLinesClosed);
        $this->assertStringNotContainsString('go to end', $screenTextClosed);

        $tui->stop();
    }

    public function testDocumentWidgetRendersAndScrolls(): void
    {
        $vt = new VirtualTerminal(80, 2);
        $tui = new Tui(terminal: $vt);
        $async = new AsyncLoader($tui);
        $tui->onTick(static fn(): bool => $async->poll());
        $widget = new DocumentWidget(new TermstrapRenderer(), $async);
        $tui->add($widget);
        $tui->setFocus($widget->scrollWidget());
        $this->assertTrue($widget->isVerticallyExpanded());

        $widget->setDocument('TEST.md', "# Heading\n\nParagraph with **bold** text.\nLine 3\nLine 4\nLine 5\nLine 6");
        $tui->start();
        $this->tickUntilSettled($tui, $vt);
        $scroll = $widget->scrollWidget();
        $this->assertGreaterThanOrEqual(3, \count($scroll->lines()));
        $this->assertSame(0, $scroll->scroll());

        $scroll->setScroll(1);
        $this->assertSame(1, $scroll->scroll());
        $tui->handleInput('k');
        $this->assertSame(0, $scroll->scroll());
        $tui->stop();
    }

    public function testBrowseCommand(): void
    {
        [$dir] = $this->createFixtureRepo();
        $vt = new VirtualTerminal(80, 24);

        $cmd = new BrowseCommand($this->apps, $this->gitReader, null, $vt);
        $tester = new CommandTester($cmd);

        EventLoop::defer(static function () use ($vt): void {
            $vt->simulateInput('q');
        });

        $status = $tester->execute(['path' => $dir]);
        $this->assertSame(0, $status);
    }

    public function testTuiCommand(): void
    {
        $vt = new VirtualTerminal(80, 24);

        $cmd = new TuiCommand($this->apps, null, $vt);
        $tester = new CommandTester($cmd);

        EventLoop::defer(static function () use ($vt): void {
            $vt->simulateInput('q');
        });

        $status = $tester->execute([]);
        $this->assertSame(0, $status);
    }

    public function testSpinnerLoaderAnimatesDuringAsyncLoading(): void
    {
        [$dir] = $this->createFixtureRepo();
        $git = $this->gitReader->openRepository($dir);
        $user = new User('spinner-user', false, new \DateTimeImmutable());
        $this->userStore->save($user);

        $vt = new VirtualTerminal(80, 24);
        $app = $this->apps->create($vt, $user, false);
        $app->start($app->views()->repoForGit($git));
        $tui = $app->tui();

        $tui->start();
        // First tick renders immediately with spinner frame 0 (⠋) while git ls-tree runs in background
        $tui->tick();
        $firstFrame = $vt->consumeOutput();
        $this->assertStringContainsString('⠋', $firstFrame);
        $this->assertStringContainsString('Loading README...', $firstFrame);

        // Wait 85ms so the real-time 80ms PeriodicStepper advances to the next frame (⠙)
        usleep(85000);
        $settledOutput = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('Demo Repository', $settledOutput);
        $tui->stop();
    }

    public function testFilesTabFuzzySearchFiltersAndOpensNestedFile(): void
    {
        [$dir] = $this->createFixtureRepo();
        mkdir($dir . '/src/Deep/Nested', 0755, true);
        file_put_contents(
            $dir . '/src/Deep/Nested/TargetService.php',
            "<?php\n\nnamespace App\\Deep\\Nested;\n\nfinal class TargetService {}\n"
        );
        exec(sprintf('cd %s && git add . && git commit -m "add nested target"', escapeshellarg($dir)));

        $git = $this->gitReader->openRepository($dir);
        $user = new User('fuzzy-user', false, new \DateTimeImmutable());
        $this->userStore->save($user);

        $vt = new VirtualTerminal(80, 24);
        $app = $this->apps->create($vt, $user, false);
        $app->start($app->views()->repoForGit($git));
        $tui = $app->tui();

        $tui->start();
        $this->tickUntilSettled($tui, $vt);

        // Switch to Files tab (shortcut 2)
        $vt->simulateInput('2');
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('README.md', $output);

        // Open fuzzy search with '/'
        $vt->simulateInput('/');
        $output = $this->tickUntilSettled($tui, $vt);
        $plain = (string) preg_replace('/\e\[[0-9;]*m/', '', $output);
        $this->assertStringContainsString('[SEARCH]', $plain);
        $this->assertStringContainsString('src/Deep/Nested/TargetService.php', $plain);
        $this->assertStringContainsString('src/Calculator.php', $plain);

        // Type fuzzy query "trgsrv" to match src/Deep/Nested/TargetService.php
        $vt->simulateInput('trgsrv');
        $output = $this->tickUntilSettled($tui, $vt);
        $plain = (string) preg_replace('/\e\[[0-9;]*m/', '', $output);
        $this->assertStringContainsString('/trgsrv', $plain);
        $this->assertStringContainsString('src/Deep/Nested/TargetService.php', $plain);
        $this->assertStringNotContainsString('README.md', $plain);
        $this->assertStringContainsString('# 1/1', $plain);

        // Press Enter to open the matched file directly
        $vt->simulateInput("\r");
        $output = $this->tickUntilSettled($tui, $vt);
        $plainCode = (string) preg_replace('/\e\[[0-9;]*m/', '', $output);
        $this->assertStringContainsString('final class TargetService', $plainCode);

        // Press Esc to exit file viewer back to directory view
        $vt->simulateInput("\e");
        $output = $this->tickUntilSettled($tui, $vt);
        $plainAfterEsc = (string) preg_replace('/\e\[[0-9;]*m/', '', $output);
        $this->assertStringContainsString('README.md', $plainAfterEsc);

        $tui->stop();
    }

    public function testCommitsBranchesAndTagsFuzzySearch(): void
    {
        $user = new User('alice-dev', true, new \DateTimeImmutable());
        $this->userStore->save($user);

        $repo = ($this->createRepo)($user, 'fuzzy-all-tabs-' . uniqid(), false, false, 'Fuzzy tabs repo');
        $repoPath = $this->repoDirectory->pathFor($repo->name());
        $gitRepo = $this->gitReader->openRepository($repoPath);

        GitTestHelper::writeFile(
            $gitRepo,
            'main',
            'README.md',
            "# Main\n",
            "Initial commit\n\nBootstrap documentation body",
            'Alice Author',
            'alice@codes.sh',
        );
        GitTestHelper::writeFile(
            $gitRepo,
            'main',
            'src/Security.php',
            "<?php\n// security\n",
            "Harden OAuth2 token validation\n\nFixes CVE-2026-9999 in token verifier",
            'Bob Security',
            'bob@security.org',
        );

        (new Process(['git', 'branch', 'feature/oauth-refresh', 'main'], $repoPath))->mustRun();
        GitTestHelper::writeFile(
            $gitRepo,
            'feature/oauth-refresh',
            'src/Refresh.php',
            "<?php\n// refresh\n",
            'Implement refresh rotation',
            'Bob Security',
            'bob@security.org',
        );
        (new Process(['git', 'tag', 'v1.0.0-alpha', 'main~1'], $repoPath))->mustRun();
        (new Process(['git', 'tag', 'v2.4.0-release', 'main'], $repoPath))->mustRun();

        $vt = new VirtualTerminal(100, 28);
        $app = $this->apps->create($vt, $user, true);
        $app->start($app->views()->repoByName($repo->nameString()));
        $tui = $app->tui();

        $tui->start();
        $this->tickUntilSettled($tui, $vt);

        // 1. Commits tab (shortcut 3): search by commit body + author ("bob cve9999")
        $vt->simulateInput('3');
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('Initial commit', $output);
        $this->assertStringContainsString('Harden OAuth2 token validation', $output);

        $vt->simulateInput('/');
        $this->tickUntilSettled($tui, $vt);
        $vt->simulateInput('bob cve9999');
        $output = $this->tickUntilSettled($tui, $vt);
        $plain = (string) preg_replace('/\e\[[0-9;]*m/', '', $output);
        $this->assertStringContainsString('/bob cve9999', $plain);
        $this->assertStringContainsString('Harden OAuth2 token validation', $plain);
        $this->assertStringNotContainsString('Initial commit', $plain);
        $this->assertStringContainsString('p. 1/1', $plain);

        // Enter opens commit diff
        $vt->simulateInput("\r");
        $output = $this->tickUntilSettled($tui, $vt);
        $plainDiff = (string) preg_replace('/\e\[[0-9;]*m/', '', $output);
        $this->assertStringContainsString('src/Security.php', $plainDiff);

        // Close diff
        $vt->simulateInput("\e");
        $this->tickUntilSettled($tui, $vt);

        // 2. Branches tab (shortcut 4): fuzzy filter branch and switch to it with Enter
        $vt->simulateInput('4');
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('feature/oauth-refresh', $output);

        $vt->simulateInput('/');
        $this->tickUntilSettled($tui, $vt);
        $vt->simulateInput('ftrfrsh');
        $output = $this->tickUntilSettled($tui, $vt);
        $plain = (string) preg_replace('/\e\[[0-9;]*m/', '', $output);
        $this->assertStringContainsString('feature/oauth-refresh', $plain);
        $this->assertStringContainsString('p. 1/1', $plain);

        $vt->simulateInput("\r");
        $output = $this->tickUntilSettled($tui, $vt);
        $plain = (string) preg_replace('/\e\[[0-9;]*m/', '', $output);
        $this->assertStringContainsString('Switched to feature/oauth-refresh', $plain);

        // 3. Tags tab (shortcut 5): fuzzy filter tags
        $vt->simulateInput('5');
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('v1.0.0-alpha', $output);
        $this->assertStringContainsString('v2.4.0-release', $output);

        $vt->simulateInput('/');
        $this->tickUntilSettled($tui, $vt);
        $vt->simulateInput('24rel');
        $output = $this->tickUntilSettled($tui, $vt);
        $plain = (string) preg_replace('/\e\[[0-9;]*m/', '', $output);
        $this->assertStringContainsString('v2.4.0-release', $plain);
        $this->assertStringNotContainsString('v1.0.0-alpha', $plain);
        $this->assertStringContainsString('p. 1/1', $plain);

        // Close tag search with Esc
        $vt->simulateInput("\e");
        $this->tickUntilSettled($tui, $vt);

        $tui->stop();
    }

    public function testSelectionPageFeedAndMyRepositoriesFuzzySearch(): void
    {
        $alice = new User('alice-owner-' . uniqid(), false, new \DateTimeImmutable());
        $this->userStore->save($alice);
        $bob = new User('bob-owner-' . uniqid(), false, new \DateTimeImmutable());
        $this->userStore->save($bob);

        ($this->createRepo)($alice, 'alpha-engine', false, false, 'High performance physics engine', 'Alpha Engine');
        ($this->createRepo)($alice, 'zeta-dashboard', false, false, 'Admin analytics UI', 'Zeta Dashboard');
        ($this->createRepo)($bob, 'omega-payments', false, false, 'Stripe billing gateway', 'Omega Payments');

        $vt = new VirtualTerminal(90, 24);
        $app = $this->apps->create($vt, $alice, true);
        $app->start($app->views()->selection());
        $tui = $app->tui();

        $tui->start();
        $this->tickUntilSettled($tui, $vt);

        // Switch to Feed tab (Tab 1)
        $vt->simulateInput("\t");
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('alpha-engine', $output);
        $this->assertStringContainsString('omega-payments', $output);

        // Open fuzzy search on Feed with '/' and filter by description + name ("stripe omg")
        $vt->simulateInput('/');
        $this->tickUntilSettled($tui, $vt);
        $vt->simulateInput('stripe omg');
        $output = $this->tickUntilSettled($tui, $vt);
        $plain = (string) preg_replace('/\e\[[0-9;]*m/', '', $output);
        $this->assertStringContainsString('omega-payments', $plain);
        $this->assertStringNotContainsString('alpha-engine', $plain);

        // Switch to My Repositories tab (Tab 2) — automatically resets fuzzy search
        $vt->simulateInput("\t");
        $output = $this->tickUntilSettled($tui, $vt);
        $plain = (string) preg_replace('/\e\[[0-9;]*m/', '', $output);
        $this->assertStringContainsString('alpha-engine', $plain);
        $this->assertStringContainsString('zeta-dashboard', $plain);
        $this->assertStringNotContainsString('omega-payments', $plain);

        // Open fuzzy search on My Repositories with '/' and filter for "zeta analytics"
        $vt->simulateInput('/');
        $this->tickUntilSettled($tui, $vt);
        $vt->simulateInput('zeta analytics');
        $output = $this->tickUntilSettled($tui, $vt);
        $plain = (string) preg_replace('/\e\[[0-9;]*m/', '', $output);
        $this->assertStringContainsString('zeta-dashboard', $plain);
        $this->assertStringNotContainsString('alpha-engine', $plain);

        // Press Enter to navigate directly to the matched repository page
        $vt->simulateInput("\r");
        $output = $this->tickUntilSettled($tui, $vt);
        $plainRepo = (string) preg_replace('/\e\[[0-9;]*m/', '', $output);
        $this->assertStringContainsString('zeta-dashboard', $plainRepo);
        $this->assertStringContainsString('Readme', $plainRepo);
        $this->assertStringContainsString('Files', $plainRepo);

        $tui->stop();
    }

    public function testSelectionPageShowsFlashMessageWhenOpeningOrphanRepositoryWithoutDiskDirectory(): void
    {
        $user = new User('orphan-tester-' . uniqid(), true, new \DateTimeImmutable());
        $this->userStore->save($user);

        $repo = ($this->createRepo)($user, 'orphan-repo-' . uniqid(), false, false, 'An orphan test repo', 'Orphan Repo');
        $repoDiskPath = $this->repoDirectory->pathFor($repo->name());
        $this->assertTrue(is_dir($repoDiskPath));
        // Simulate the orphan state by deleting the git repository directory on disk
        (new Process(['rm', '-rf', $repoDiskPath]))->mustRun();
        $this->assertFalse(is_dir($repoDiskPath));

        $vt = new VirtualTerminal(90, 24);
        $app = $this->apps->create($vt, $user, true);
        $app->start($app->views()->selection());
        $tui = $app->tui();

        $tui->start();
        $this->tickUntilSettled($tui, $vt);

        // Switch to Feed tab (Tab 1) where all public repos are visible
        $vt->simulateInput("\t");
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString($repo->nameString(), $output);

        // Press Enter on the orphan repo
        $vt->simulateInput("\r");
        $output = $this->tickUntilSettled($tui, $vt);
        $plain = (string) preg_replace('/\e\[[0-9;]*m/', '', $output);
        $this->assertStringContainsString('directory does not exist on disk', $plain);
        $this->assertStringContainsString($repo->nameString(), $plain);

        // Pressing down/up clears the flash
        $vt->simulateInput('j');
        $this->tickUntilSettled($tui, $vt);

        // Pressing 'l' also triggers selection and shows the flash message again
        $vt->simulateInput('l');
        $output = $this->tickUntilSettled($tui, $vt);
        $plain = (string) preg_replace('/\e\[[0-9;]*m/', '', $output);
        $this->assertStringContainsString('directory does not exist on disk', $plain);
        $tui->stop();
    }

    public function testSelectionPageMouseClickSwitchesTabAndOpensRepo(): void
    {
        [$dir] = $this->createFixtureRepo();
        $user = new User('mia', false, new \DateTimeImmutable());
        $this->userStore->save($user);
        $repo = ($this->createRepo)($user, 'clickable', false, false, 'Clickable repo', 'clickable');
        // Point the repo at the fixture working tree so it opens on disk.
        $target = $this->repoDirectory->pathFor($repo->name());
        (new Process(['rm', '-rf', $target]))->run();
        (new Process(['cp', '-R', $dir . '/.git', $target]))->run();

        $vt = new VirtualTerminal(80, 24);
        $app = $this->apps->create($vt, $user, true);
        $app->start($app->views()->selection());
        $tui = $app->tui();
        $tui->start();
        $this->tickUntilSettled($tui, $vt);

        // Click the "Feed" tab label (row y=4; "• About" is 7 cells, a 3-cell gap,
        // then "Feed" begins at inner col 10 → terminal x=13).
        $vt->simulateInput("\e[<0;13;4M");
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('clickable', $output);

        // The first feed row is already selected, so a single click on it (body
        // starts at y=6) opens the repo.
        $vt->simulateInput("\e[<0;6;6M");
        $output = $this->tickUntilSettled($tui, $vt);
        // RepoPage chrome (tab bar + status) is now visible — proves the click opened the repo.
        $this->assertStringContainsString('Commits', $output);
        $this->assertStringContainsString('? Help', $output);

        $tui->stop();
    }
}

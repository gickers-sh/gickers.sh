<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tui;

use App\Access\Application\ServerPolicy;
use App\CodeIntel\Domain\IndexStore;
use App\CodeIntel\Infrastructure\Scip\ScipProtobufCodec;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\PullRequest\Domain\Model\PullRequest;
use App\PullRequest\Domain\PullRequestStore;
use App\Repo\Application\CreateRepo;
use App\Repo\Domain\RepoDirectory;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Repo\Infrastructure\Git\GitRepositoryWriter;
use App\UI\Console\Command\Admin\ReindexCodeIntelCommand;
use App\UI\Console\Command\BrowseCommand;
use App\UI\Console\Command\TuiCommand;
use App\UI\Tui\Render\TermstrapRenderer;
use App\UI\Tui\TuiPageFactory;
use App\UI\Tui\Widget\AnsiDocumentWidget;
use Revolt\EventLoop;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class TuiFunctionalTest extends KernelTestCase
{
    private TuiPageFactory $factory;
    private UserStore $userStore;
    private RepoStore $repoStore;
    private CreateRepo $createRepo;
    private ServerPolicy $serverPolicy;
    private GitRepositoryReader $gitReader;
    private IndexStore $indexStore;
    private RepoDirectory $repoDirectory;
    private GitRepositoryWriter $gitWriter;
    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->factory = $container->get(TuiPageFactory::class);
        $this->userStore = $container->get(UserStore::class);
        $this->repoStore = $container->get(RepoStore::class);
        $this->createRepo = $container->get(CreateRepo::class);
        $this->serverPolicy = $container->get(ServerPolicy::class);
        $this->gitReader = $container->get(GitRepositoryReader::class);
        $this->indexStore = $container->get(IndexStore::class);
        $this->repoDirectory = $container->get(RepoDirectory::class);
        $this->gitWriter = new GitRepositoryWriter();
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
        $tui = new Tui(terminal: $vt);
        $root = $this->factory->createRoot($tui, $user);
        $page = $this->factory->selectionPage($user);
        $root->start($page);

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

    public function testSelectionPageNewRepoModalCreatesRepo(): void
    {
        $user = new User('bob', false, new \DateTimeImmutable());
        $this->userStore->save($user);

        $vt = new VirtualTerminal(80, 24);
        $tui = new Tui(terminal: $vt);
        $root = $this->factory->createRoot($tui, $user);
        $page = $this->factory->selectionPage($user);
        $root->start($page);

        $tui->start();
        $tui->tick();
        $vt->clearOutput();

        // Open modal
        $vt->simulateInput('n');
        $tui->tick();
        $output = $vt->consumeOutput();
        $this->assertStringContainsString('New repository', $output);
        $this->assertStringContainsString('Name', $output);

        // Type repo name: "shiny-repo"
        $vt->simulateInput('shiny-repo');
        $tui->tick();
        $output = $vt->consumeOutput();
        $this->assertStringContainsString('shiny-repo', $output);

        // Press Tab to description field
        $vt->simulateInput("\t");
        $tui->tick();

        // Type description: "A shiny project"
        $vt->simulateInput('A shiny project');
        $tui->tick();

        // Press Tab to private toggle
        $vt->simulateInput("\t");
        $tui->tick();

        // Toggle private with space
        $vt->simulateInput(' ');
        $tui->tick();

        // Submit with Enter
        $vt->simulateInput("\r");
        $tui->tick();

        $output = $vt->consumeOutput();
        $this->assertStringContainsString('Created shiny-repo', $output);

        // Verify repository was created in DB
        $created = $this->repoStore->byName(RepoName::fromInput('shiny-repo'));
        $this->assertNotNull($created);
        $this->assertTrue($created->isPrivate());
        $this->assertSame('A shiny project', $created->description());

        $tui->stop();
    }

    public function testSelectionPageHelpModal(): void
    {
        $vt = new VirtualTerminal(80, 24);
        $tui = new Tui(terminal: $vt);
        $root = $this->factory->createRoot($tui, null);
        $page = $this->factory->selectionPage(null);
        $root->start($page);

        $tui->start();
        $tui->tick();
        $vt->clearOutput();

        // Open expanded help grid
        $vt->simulateInput('?');
        $tui->tick();
        $output = $vt->consumeOutput();
        $this->assertStringContainsString('go to end', $output);
        $this->assertStringContainsString('toggle help', $output);

        // Toggle help off with '?'
        $vt->simulateInput('?');
        $tui->tick();
        $output = $vt->consumeOutput();
        $this->assertStringNotContainsString('go to end', $output);

        $tui->stop();
    }

    public function testRepoPageWithCodeIntelQuery(): void
    {
        [$dir, $headSha] = $this->createFixtureRepo();
        $git = $this->gitReader->openRepository($dir);

        // Store SCIP index for this repository and commit:
        // Definition of add() at line 6, col 20..23 in src/Calculator.php
        // Reference to add() at line 8, col 15..18 in src/Calculator.php
        $codec = new ScipProtobufCodec();
        $docs = [
            [
                'relative_path' => 'src/Calculator.php',
                'language' => 'php',
                'occurrences' => [
                    [
                        'range' => [6, 0, 50],
                        'symbol' => 'App/Calculator#add().',
                        'symbol_roles' => ScipProtobufCodec::SYMBOL_ROLE_DEFINITION,
                    ],
                    [
                        'range' => [8, 0, 50],
                        'symbol' => 'App/Calculator#add().',
                        'symbol_roles' => 0,
                    ],
                ],
            ],
        ];
        $binary = $codec->encodeIndex($docs);
        $this->indexStore->store($git->name, $headSha, $binary);

        $user = new User('charlie', false, new \DateTimeImmutable());
        $this->userStore->save($user);

        $vt = new VirtualTerminal(80, 24);
        $tui = new Tui(terminal: $vt);
        $root = $this->factory->createBrowseRoot($tui, $user);
        $page = $this->factory->repoPageForGit($user, $git);
        $root->start($page);

        $tui->start();
        // Tab 1: Readme (renders spinner first, then resolves README)
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('Readme', $output);
        $this->assertStringContainsString('Loading README...', $output);
        $this->assertStringContainsString('Demo Repository', $output);

        // Tab 2: Switch to Files tab
        $vt->simulateInput("\t");
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('README.md', $output);
        $this->assertStringContainsString('src', $output);

        // Move to src and enter directory
        $vt->simulateInput('j');
        $tui->tick();
        $vt->simulateInput("\r");
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('Loading files...', $output);
        $this->assertStringContainsString('Calculator.php', $output);

        // Open Calculator.php
        $vt->simulateInput("\r");
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('Loading src/Calculator.php...', $output);
        $plainCode = (string) preg_replace('/\e\[[0-9;]*m/', '', $output);
        $this->assertStringContainsString('class Calculator', $plainCode);
        $this->assertStringContainsString('public function add', $plainCode);

        // Move cursor down to line 8 (where reference is)
        for ($i = 0; $i < 8; ++$i) {
            $vt->simulateInput('j');
            $tui->tick();
        }

        // Jump to definition with 'd'
        $vt->simulateInput('d');
        $output = $this->tickUntilSettled($tui, $vt);
        // Cursor is now on definition line 6
        $this->assertStringContainsString('Calculator.php', $output);

        // Trigger find references with 'r'
        $vt->simulateInput('r');
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('1 reference(s)', $output);
        $this->assertStringContainsString('src/Calculator.php', $output);
        // Split preview pane renders the referenced file content centered on the ref line (0-indexed 8).
        $plainRefs = (string) preg_replace('/\e\[[0-9;]*m/', '', $output);
        $this->assertStringContainsString('return $a + $b', $plainRefs);
        $this->assertStringContainsString('src/Calculator.php:9', $plainRefs);
        $this->assertStringContainsString('▶', $output);

        // Jump to selected reference with Enter
        $vt->simulateInput("\r");
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringNotContainsString('1 reference(s)', $output);

        // Cmd/Ctrl+click navigation: a modified click on the definition symbol
        // (source line 7 / body row 7) lists references, mirroring Go symbolNavCmds.
        $filesTab = $page->activeTab();
        $this->assertTrue($filesTab->onClick(7, 10, true));
        $page->sync();
        $tui->requestRender();
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('1 reference(s)', $output);
        // A plain (non-nav) click on the same cell does not trigger navigation.
        $this->assertFalse($filesTab->onClick(7, 10, false));
        $vt->simulateInput("\x1b"); // close references
        $tui->tick();

        // Exit file viewer with Esc
        $vt->simulateInput("\x1b");
        $tui->tick();

        // Test Commits tab
        $vt->simulateInput("\t");
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('Loading commits...', $output);
        $this->assertStringContainsString('initial commit', $output);

        // Test Branches tab
        $vt->simulateInput("\t");
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('Loading branches...', $output);
        $this->assertStringContainsString('main', $output);

        // Test Tags tab
        $vt->simulateInput("\t");
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('v1.0.0', $output);

        // Test Pull Requests tab
        $vt->simulateInput("\t");
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('Pull requests', $output);

        // Test Profile tab
        $vt->simulateInput("\t");
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('No profile available for this repository.', $output);
        // Test Settings tab
        $vt->simulateInput("\t");
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('Local repository', $output);
        $tui->stop();
    }

    public function testAdminPage(): void
    {
        $admin = new User('superadmin', true, new \DateTimeImmutable());
        $this->userStore->save($admin);

        $normal = new User('developer', false, new \DateTimeImmutable());
        $this->userStore->save($normal);

        $vt = new VirtualTerminal(80, 24);
        $tui = new Tui(terminal: $vt);
        $root = $this->factory->createRoot($tui, $admin);
        $page = $this->factory->adminPage($admin);
        $root->start($page);

        $tui->start();
        $tui->tick();
        $output = $vt->consumeOutput();
        $this->assertStringContainsString('Server administration', $output);
        $this->assertStringContainsString('Anonymous access', $output);
        $this->assertStringContainsString('Allow keyless', $output);
        $this->assertStringContainsString('superadmin', $output);
        $this->assertStringContainsString('developer', $output);

        $initialLevel = $this->serverPolicy->anonAccess();
        // Cycle anon access with 'a'
        $vt->simulateInput('a');
        $tui->tick();
        $nextLevel = $this->serverPolicy->anonAccess();
        $this->assertNotSame($initialLevel, $nextLevel);

        $initialKeyless = $this->serverPolicy->allowKeyless();
        // Toggle keyless access with 'k'
        $vt->simulateInput('k');
        $tui->tick();
        $this->assertSame(!$initialKeyless, $this->serverPolicy->allowKeyless());
        $vt->consumeOutput();

        // Press 'q' -> navigates back to selection page
        $vt->simulateInput('q');
        $tui->tick();
        $output = $vt->consumeOutput();
        $this->assertStringContainsString('Feed', $output);

        $tui->stop();
    }

    public function testAnsiDocumentWidget(): void
    {
        $renderer = new TermstrapRenderer();
        $widget = new AnsiDocumentWidget($renderer);

        $widget->setDocument('TEST.md', "# Heading\n\nParagraph with **bold** text.\nLine 3\nLine 4");
        $this->assertTrue($widget->isVerticallyExpanded());
        $this->assertSame(0, $widget->getScroll());

        $lines = $widget->getLines(80);
        $this->assertNotEmpty($lines);
        $this->assertGreaterThanOrEqual(3, $widget->lineCount(80));

        $widget->setScroll(2);
        $this->assertSame(2, $widget->getScroll());

        $widget->scrollBy(1);
        $this->assertSame(3, $widget->getScroll());

        $widget->expandVertically(false);
        $this->assertFalse($widget->isVerticallyExpanded());
    }

    public function testBrowseCommand(): void
    {
        [$dir] = $this->createFixtureRepo();
        $vt = new VirtualTerminal(80, 24);

        $cmd = new BrowseCommand($this->factory, $this->gitReader, null, $vt);
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

        $cmd = new TuiCommand($this->factory, null, $vt);
        $tester = new CommandTester($cmd);

        EventLoop::defer(static function () use ($vt): void {
            $vt->simulateInput('q');
        });

        $status = $tester->execute([]);
        $this->assertSame(0, $status);
    }

    public function testAdminReindexCodeIntelCommand(): void
    {
        $container = static::getContainer();
        $reindexCmd = $container->get(ReindexCodeIntelCommand::class);
        $tester = new CommandTester($reindexCmd);

        $user = new User('dave', false, new \DateTimeImmutable());
        $this->userStore->save($user);

        $repo = ($this->createRepo)($user, 'reindex-project', false, false, 'Project to reindex');

        $repoPath = $this->repoDirectory->pathFor($repo->name());
        $gitRepo = $this->gitReader->openRepository($repoPath);
        $this->gitWriter->writeFile($gitRepo, 'main', 'src/App.php', "<?php\n\nclass App {}\n", 'add code', 'Tester', 'test@codes.sh');
        $status = $tester->execute(['repo' => 'reindex-project']);
        $this->assertSame(0, $status);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Indexing reindex-project', $output);
        $this->assertStringContainsString('reindex-project: OK', $output);
    }

    public function testRepoPagePullRequestsListAndMerge(): void
    {
        $container = static::getContainer();
        $user = new User('prowner' . uniqid(), true, new \DateTimeImmutable());
        $this->userStore->save($user);

        $repo = ($this->createRepo)($user, 'pulls-repo-' . uniqid(), false, false, 'PR testing repo');
        $repoPath = $this->repoDirectory->pathFor($repo->name());
        $gitRepo = $this->gitReader->openRepository($repoPath);

        (new Process(['git', 'config', 'user.name', 'Tester'], $repoPath))->mustRun();
        (new Process(['git', 'config', 'user.email', 'test@example.com'], $repoPath))->mustRun();

        $this->gitWriter->writeFile($gitRepo, 'main', 'base.txt', "base content\n", 'Initial commit', 'Tester', 'test@example.com');
        (new Process(['git', 'branch', 'feature-pr', 'main'], $repoPath))->mustRun();
        $this->gitWriter->writeFile($gitRepo, 'feature-pr', 'feature.txt', "feature content\n", 'Feature commit', 'Tester', 'test@example.com');

        $openPr = $container->get('test.open_pull_request');
        $pr = $openPr($user, $repo->name(), 'Add feature PR', 'This is a description', 'feature-pr', 'main');
        $this->assertSame(1, $pr->number());

        $vt = new VirtualTerminal(80, 24);
        $tui = new Tui(terminal: $vt);
        $root = $this->factory->createRoot($tui, $user);
        $page = $this->factory->repoPageForName($user, $repo->nameString());
        $root->start($page);

        $tui->start();
        $this->tickUntilSettled($tui, $vt);

        // Switch to Pull Requests tab (shortcut 6)
        $vt->simulateInput('6');
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('#1', $output);
        $this->assertStringContainsString('Add feature PR', $output);
        $this->assertStringContainsString('[OPEN]', $output);
        $this->assertStringContainsString('(main ← feature-pr)', $output);

        // Open detail view with Enter
        $vt->simulateInput("\r");
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('#1 Add feature PR', $output);
        $this->assertStringContainsString('This is a description', $output);
        $this->assertStringContainsString('About', $output);
        $this->assertStringContainsString('Commits', $output);
        $this->assertStringContainsString('Changes', $output);
        $this->assertStringContainsString('Comments', $output);

        $this->assertStringContainsString('wants to merge into', $output);
        $this->assertStringContainsString('✓ Able to merge cleanly', $output);

        // Navigate sub-tabs: Tab -> Commits (2), Tab -> Changes (3), Tab -> Comments (4), 1 -> About
        $vt->simulateInput("\t");
        $tui->tick();
        $commitsOut = $vt->consumeOutput();
        $this->assertStringContainsString('Commits (1):', $commitsOut);
        $this->assertStringContainsString('Feature commit', $commitsOut);

        $vt->simulateInput("\t");
        $tui->tick();
        $changesOut = $vt->consumeOutput();
        $this->assertStringContainsString('Files changed (1):', $changesOut);
        $this->assertStringContainsString('feature.txt', $changesOut);

        $vt->simulateInput("\t");
        $tui->tick();
        $commentsOut = $vt->consumeOutput();
        $this->assertStringContainsString('Comments (0):', $commentsOut);
        $this->assertStringContainsString('No comments yet.', $commentsOut);

        $vt->simulateInput('1');
        $tui->tick();
        $vt->consumeOutput();

        // Press 'e' to open Edit Pull Request #1 modal
        $vt->simulateInput('e');
        $tui->tick();
        $editOut = $vt->consumeOutput();
        $this->assertStringContainsString('Edit Pull Request #1', $editOut);
        $this->assertStringContainsString('tab switch field', $editOut);

        // Press Esc to close Edit modal
        $vt->simulateInput("\e");
        $tui->tick();
        $vt->consumeOutput();

        // Press 'm' to merge
        $vt->simulateInput('m');
        $tui->tick();
        $output = $vt->consumeOutput();
        $this->assertStringContainsString('Merge pull request', $output);
        $this->assertStringContainsString('Merge PR #1', $output);
        // Press 'y' to confirm merge
        $vt->simulateInput('y');
        $tui->tick();
        $output = $vt->consumeOutput();
        $this->assertStringContainsString('Merged PR #1', $output);

        // Verify status in DB
        /** @var \App\PullRequest\Application\Query\PullRequestQuery $prQuery */
        $prQuery = $container->get('test.pull_request_query');
        $updatedPr = $prQuery->get($user, $repo->name(), 1);
        $this->assertTrue($updatedPr->isMerged());

        $tui->stop();
    }

    public function testRepoPageSettingsEditModalForm(): void
    {
        $user = new User('settings-tester', true, new \DateTimeImmutable());
        $this->userStore->save($user);

        $repo = ($this->createRepo)($user, 'settings-test-repo', false, false, 'Initial description', 'initial-project');

        $vt = new VirtualTerminal(80, 24);
        $tui = new Tui(terminal: $vt);
        $root = $this->factory->createRoot($tui, $user);
        $page = $this->factory->repoPageForName($user, $repo->nameString());
        $root->start($page);

        $tui->start();
        $this->tickUntilSettled($tui, $vt);

        // Switch to Settings tab (shortcut 8)
        $vt->simulateInput('8');
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('Repository settings', $output);
        $this->assertStringContainsString('ADMIN', $output);
        $this->assertStringContainsString('Initial description', $output);
        $this->assertStringContainsString('initial-project', $output);
        $this->assertStringContainsString('press e to edit settings', $output);

        // Press 'e' to enter editing mode
        $vt->simulateInput('e');
        $tui->tick();
        $output = $vt->consumeOutput();
        $this->assertStringContainsString('Repository settings', $output);
        $this->assertStringContainsString('EDITING', $output);
        $this->assertStringContainsString('Project name', $output);
        $this->assertStringContainsString('Description', $output);
        $this->assertStringContainsString('Visibility & Access', $output);
        // Private/Hidden toggle rows are not rendered in SettingsTab card
        $this->assertStringContainsString('ctrl+s save', $output);

        // Enter on Project name moves to Description
        $vt->simulateInput("\r");
        $tui->tick();

        // Clear and update description
        for ($i = 0; $i < 25; ++$i) {
            $vt->simulateInput("\x7f");
        }
        $vt->simulateInput('Updated description');
        $tui->tick();

        // Enter on Description moves to Private toggle
        $vt->simulateInput("\r");
        $tui->tick();

        // Space toggles Private
        $vt->simulateInput(' ');
        $tui->tick();
        $vt->consumeOutput();

        // Down arrow / Tab moves to Hidden toggle
        $vt->simulateInput("\t");
        $tui->tick();

        // Space toggles Hidden
        $vt->simulateInput(' ');
        $tui->tick();
        $vt->consumeOutput();

        // Submit with Ctrl+S (\x13)
        $vt->simulateInput("\x13");
        $tui->tick();
        $output = $vt->consumeOutput();
        $this->assertStringContainsString('Settings saved.', $output);

        // Verify in DB
        $reloaded = $this->repoStore->byName($repo->name());
        $this->assertNotNull($reloaded);
        $this->assertSame('Updated description', $reloaded->description());
        $this->assertSame('initial-project', $reloaded->projectName());
        $this->assertTrue($reloaded->isPrivate());
        $this->assertTrue($reloaded->isHidden());

        $tui->stop();
    }

    public function testSpinnerLoaderAnimatesDuringAsyncLoading(): void
    {
        [$dir] = $this->createFixtureRepo();
        $git = $this->gitReader->openRepository($dir);
        $user = new User('spinner-user', false, new \DateTimeImmutable());
        $this->userStore->save($user);

        $vt = new VirtualTerminal(80, 24);
        $tui = new Tui(terminal: $vt);
        $root = $this->factory->createBrowseRoot($tui, $user);
        $page = $this->factory->repoPageForGit($user, $git);
        $root->start($page);

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
        $tui = new Tui(terminal: $vt);
        $root = $this->factory->createBrowseRoot($tui, $user);
        $page = $this->factory->repoPageForGit($user, $git);
        $root->start($page);

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

    public function testCommitsBranchesTagsAndPullsFuzzySearch(): void
    {
        $container = static::getContainer();
        $user = new User('alice-dev', true, new \DateTimeImmutable());
        $this->userStore->save($user);
        $bob = new User('bob-reviewer', false, new \DateTimeImmutable());
        $this->userStore->save($bob);

        $repo = ($this->createRepo)($user, 'fuzzy-all-tabs-' . uniqid(), false, false, 'Fuzzy tabs repo');
        $repoPath = $this->repoDirectory->pathFor($repo->name());
        $gitRepo = $this->gitReader->openRepository($repoPath);

        $this->gitWriter->writeFile(
            $gitRepo,
            'main',
            'README.md',
            "# Main\n",
            "Initial commit\n\nBootstrap documentation body",
            'Alice Author',
            'alice@codes.sh',
        );
        $this->gitWriter->writeFile(
            $gitRepo,
            'main',
            'src/Security.php',
            "<?php\n// security\n",
            "Harden OAuth2 token validation\n\nFixes CVE-2026-9999 in token verifier",
            'Bob Security',
            'bob@security.org',
        );

        (new Process(['git', 'branch', 'feature/oauth-refresh', 'main'], $repoPath))->mustRun();
        $this->gitWriter->writeFile(
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

        $prStore = $container->get(PullRequestStore::class);
        $now = new \DateTimeImmutable();
        $prStore->save(new PullRequest($repo, 1, 'Add README docs', 'Documentation update only', 'main', str_repeat('a', 40), $repo, 'feature/oauth-refresh', str_repeat('b', 40), $user, $now));
        $prStore->save(new PullRequest($repo, 2, 'Rotate OAuth refresh tokens', 'Prevents replay attacks on mobile clients', 'main', str_repeat('a', 40), $repo, 'feature/oauth-refresh', str_repeat('b', 40), $bob, $now));

        $vt = new VirtualTerminal(100, 28);
        $tui = new Tui(terminal: $vt);
        $root = $this->factory->createRoot($tui, $user);
        $page = $this->factory->repoPageForName($user, $repo->nameString());
        $root->start($page);

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

        // 4. Pull Requests tab (shortcut 6): fuzzy filter by author + description ("bob replay")
        $vt->simulateInput('6');
        $output = $this->tickUntilSettled($tui, $vt);
        $this->assertStringContainsString('Add README docs', $output);
        $this->assertStringContainsString('Rotate OAuth refresh tokens', $output);

        $vt->simulateInput('/');
        $this->tickUntilSettled($tui, $vt);
        $vt->simulateInput('bob replay');
        $output = $this->tickUntilSettled($tui, $vt);
        $plain = (string) preg_replace('/\e\[[0-9;]*m/', '', $output);
        $this->assertStringContainsString('Rotate OAuth refresh tokens', $plain);
        $this->assertStringNotContainsString('Add README docs', $plain);
        $this->assertStringContainsString('# 1/1', $plain);

        // Press Enter to open PR detail view
        $vt->simulateInput("\r");
        $output = $this->tickUntilSettled($tui, $vt);
        $plainDetail = (string) preg_replace('/\e\[[0-9;]*m/', '', $output);
        $this->assertStringContainsString('#2 Rotate OAuth refresh tokens', $plainDetail);
        $this->assertStringContainsString('Prevents replay attacks on mobile clients', $plainDetail);

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
        $tui = new Tui(terminal: $vt);
        $root = $this->factory->createRoot($tui, $alice);
        $page = $this->factory->selectionPage($alice);
        $root->start($page);

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
        $tui = new Tui(terminal: $vt);
        $root = $this->factory->createRoot($tui, $user);
        $page = $this->factory->selectionPage($user);
        $root->start($page);

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
        $tui = new Tui(terminal: $vt);
        $root = $this->factory->createRoot($tui, $user);
        $page = $this->factory->selectionPage($user);
        $root->start($page);
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

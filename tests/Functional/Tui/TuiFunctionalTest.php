<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tui;

use App\Access\Application\ServerPolicy;
use App\CodeIntel\Domain\IndexStore;
use App\CodeIntel\Infrastructure\Scip\ScipProtobufCodec;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
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
        $tui->tick();

        $output = $vt->consumeOutput();
        $this->assertStringContainsString('Feed', $output);
        $this->assertStringContainsString('My Repositories', $output);
        $this->assertStringContainsString('About', $output);
        $this->assertStringContainsString('Profile', $output);
        $this->assertStringContainsString('feed-project', $output);

        // Switch to My Repositories
        $vt->simulateInput("\t");
        $tui->tick();
        $output = $vt->consumeOutput();
        $this->assertStringContainsString('My Repositories', $output);

        // Switch to About
        $vt->simulateInput("\t");
        $tui->tick();
        $output = $vt->consumeOutput();
        $this->assertStringContainsString('No readme found.', $output);

        // Switch to Profile
        $vt->simulateInput("\t");
        $tui->tick();
        $output = $vt->consumeOutput();
        $this->assertStringContainsString('Username', $output);
        $this->assertStringContainsString('alice', $output);
        // Backtab back to About
        $vt->simulateInput("\x1b[Z");
        $tui->tick();
        $output = $vt->consumeOutput();
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
        $tui->tick();

        // Tab 1: Readme
        $output = $vt->consumeOutput();
        $this->assertStringContainsString('Readme', $output);
        $this->assertStringContainsString('Demo Repository', $output);

        // Tab 2: Switch to Files tab
        $vt->simulateInput("\t");
        $tui->tick();
        $output = $vt->consumeOutput();
        $this->assertStringContainsString('README.md', $output);
        $this->assertStringContainsString('src', $output);

        // Move to src and enter directory
        $vt->simulateInput('j');
        $tui->tick();
        $vt->simulateInput("\r");
        $tui->tick();
        $output = $vt->consumeOutput();
        $this->assertStringContainsString('Calculator.php', $output);

        // Open Calculator.php
        $vt->simulateInput("\r");
        $tui->tick();
        $output = $vt->consumeOutput();
        $this->assertStringContainsString('class Calculator', $output);
        $this->assertStringContainsString('public function add', $output);

        // Move cursor down to line 8 (where reference is)
        for ($i = 0; $i < 8; ++$i) {
            $vt->simulateInput('j');
            $tui->tick();
        }

        // Jump to definition with 'd'
        $vt->simulateInput('d');
        $tui->tick();
        $output = $vt->consumeOutput();
        // Cursor is now on definition line 6
        $this->assertStringContainsString('Calculator.php', $output);

        // Trigger find references with 'r'
        $vt->simulateInput('r');
        $tui->tick();
        $output = $vt->consumeOutput();
        $this->assertStringContainsString('1 reference(s)', $output);
        $this->assertStringContainsString('src/Calculator.php', $output);

        // Jump to selected reference with Enter
        $vt->simulateInput("\r");
        $tui->tick();
        $output = $vt->consumeOutput();
        $this->assertStringNotContainsString('1 reference(s)', $output);

        // Exit file viewer with Esc
        $vt->simulateInput("\x1b");
        $tui->tick();

        // Test Commits tab
        $vt->simulateInput("\t");
        $tui->tick();
        $output = $vt->consumeOutput();
        $this->assertStringContainsString('initial commit', $output);

        // Test Branches tab
        $vt->simulateInput("\t");
        $tui->tick();
        $output = $vt->consumeOutput();
        $this->assertStringContainsString('main', $output);

        // Test Tags tab
        $vt->simulateInput("\t");
        $tui->tick();
        $output = $vt->consumeOutput();
        $this->assertStringContainsString('v1.0.0', $output);

        // Test Pull Requests tab
        $vt->simulateInput("\t");
        $tui->tick();
        $output = $vt->consumeOutput();
        $this->assertStringContainsString('Pull requests', $output);

        // Test Profile tab
        $vt->simulateInput("\t");
        $tui->tick();
        $output = $vt->consumeOutput();
        $this->assertStringContainsString('No profile available for this repository.', $output);
        // Test Settings tab
        $vt->simulateInput("\t");
        $tui->tick();
        $output = $vt->consumeOutput();
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
        $tui->tick();

        // Switch to Pull Requests tab (shortcut 6)
        $vt->simulateInput('6');
        $tui->tick();
        $output = $vt->consumeOutput();
        $this->assertStringContainsString('#1', $output);
        $this->assertStringContainsString('Add feature PR', $output);
        $this->assertStringContainsString('open', $output);
        $this->assertStringContainsString('feature-pr → main', $output);

        // Open detail view with Enter
        $vt->simulateInput("\r");
        $tui->tick();
        $output = $vt->consumeOutput();
        $this->assertStringContainsString('#1: Add feature PR', $output);
        $this->assertStringContainsString('This is a description', $output);
        $this->assertStringContainsString('m: merge', $output);

        // Press 'm' to merge
        $vt->simulateInput('m');
        $tui->tick();
        $output = $vt->consumeOutput();
        $this->assertStringContainsString('Confirm merge', $output);
        $this->assertStringContainsString('Merge pull request #1', $output);

        // Press 'y' to confirm merge
        $vt->simulateInput('y');
        $tui->tick();
        $output = $vt->consumeOutput();
        $this->assertStringContainsString('Merged PR #1', $output);

        // Verify status in DB
        $prQuery = $container->get('test.pull_request_query');
        $updatedPr = $prQuery->get($user, $repo->name(), 1);
        $this->assertTrue($updatedPr->isMerged());

        $tui->stop();
    }
}

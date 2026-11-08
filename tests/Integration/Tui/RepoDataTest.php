<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tui;

use App\Repo\Domain\Git\Commit;
use App\Repo\Domain\Git\TreeEntry;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\UI\Tui\AsyncLoader;
use App\UI\Tui\View\Repo\RepoData;
use App\UI\Tui\View\Repo\RepoState;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class RepoDataTest extends KernelTestCase
{
    private string $tempDir;
    private string $headSha;
    private GitRepositoryReader $reader;
    private AsyncLoader $async;
    private RepoState $state;
    private RepoData $data;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/repo_data_test_'.uniqid('', true);
        mkdir($this->tempDir, 0755, true);

        $cmds = [
            'cd '.escapeshellarg($this->tempDir),
            'git init -b main',
            'git config user.email "test@example.com"',
            'git config user.name "Tester"',
        ];
        exec(implode(' && ', $cmds));

        file_put_contents($this->tempDir.'/README.md', "# Demo Repository\n\nWelcome to demo.");
        mkdir($this->tempDir.'/src', 0755, true);
        file_put_contents(
            $this->tempDir.'/src/Calculator.php',
            "<?php\n\nnamespace App;\n\nclass Calculator {}\n"
        );

        exec('cd '.escapeshellarg($this->tempDir).' && git add . && git commit -m "initial commit"');
        exec('cd '.escapeshellarg($this->tempDir).' && git tag v1.0.0');
        exec('cd '.escapeshellarg($this->tempDir).' && git branch feature-1');

        $this->headSha = trim((string) shell_exec('cd '.escapeshellarg($this->tempDir).' && git rev-parse HEAD'));

        $this->reader = new GitRepositoryReader();
        $git = $this->reader->openRepository($this->tempDir);

        $vt = new VirtualTerminal(80, 24);
        $tui = new Tui(terminal: $vt);
        $this->async = new AsyncLoader($tui);

        $this->state = new RepoState($git, null, null, 'git@codes.sh:acme/demo.git');
        $this->data = new RepoData($this->state, $this->reader, $this->async);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            (new Process(['rm', '-rf', $this->tempDir]))->run();
        }
    }

    private function drainAsync(int $maxLoops = 150): void
    {
        while ($this->async->poll() && $maxLoops-- > 0) {
            usleep(10000);
        }
    }

    public function testInitialState(): void
    {
        $this->assertSame('main', $this->state->ref);
        $this->assertSame('', $this->state->subpath);
        $this->assertSame('git clone git@codes.sh:acme/demo.git/'.basename($this->tempDir).'.git', $this->state->cloneCommand());
        $this->assertTrue($this->state->currentRefIsBranch());
        $this->assertSame($this->headSha, $this->state->sha());
        $this->assertSame($this->headSha, $this->data->sha());
    }

    public function testAsyncLoadingAndCallbacks(): void
    {
        /** @var list<TreeEntry>|null $treeResult */
        $treeResult = null;
        /** @var list<TreeEntry>|null $recursiveTreeResult */
        $recursiveTreeResult = null;
        /** @var list<Commit>|null $commitsResult */
        $commitsResult = null;
        /** @var array{branches: list<string>, tags: list<string>, current: string}|null $refsResult */
        $refsResult = null;
        /** @var array{path: string, content: string}|null $readmeResult */
        $readmeResult = null;

        $this->data->tree('', static function (array $entries) use (&$treeResult): void {
            $treeResult = $entries;
        });

        $this->data->recursiveTree(static function (array $entries) use (&$recursiveTreeResult): void {
            $recursiveTreeResult = $entries;
        });

        $this->data->commits(static function (array $commits) use (&$commitsResult): void {
            $commitsResult = $commits;
        });

        $this->data->refs(static function (array $refs) use (&$refsResult): void {
            $refsResult = $refs;
        });

        $this->data->readme(static function (?array $doc) use (&$readmeResult): void {
            $readmeResult = $doc;
        });

        $this->assertTrue($this->data->isLoading('tree'));
        $this->assertTrue($this->data->isLoading('recursiveTree'));
        $this->assertTrue($this->data->isLoading('commits'));
        $this->assertTrue($this->data->isLoading('refs'));
        $this->assertTrue($this->data->isLoading('readme'));

        $this->drainAsync();

        $this->assertNotNull($treeResult);
        $treePaths = array_map(static fn (TreeEntry $e): string => $e->path, $treeResult);
        $this->assertContains('README.md', $treePaths);
        $this->assertContains('src', $treePaths);

        $this->assertNotNull($recursiveTreeResult);
        $recursivePaths = array_map(static fn (TreeEntry $e): string => $e->path, $recursiveTreeResult);
        $this->assertContains('README.md', $recursivePaths);
        $this->assertContains('src/Calculator.php', $recursivePaths);

        $this->assertNotNull($commitsResult);
        $this->assertNotEmpty($commitsResult);
        $this->assertSame('initial commit', $commitsResult[0]->subject);
        $this->assertSame($this->headSha, $commitsResult[0]->hash);

        $this->assertNotNull($refsResult);
        $this->assertContains('main', $refsResult['branches']);
        $this->assertContains('feature-1', $refsResult['branches']);
        $this->assertContains('v1.0.0', $refsResult['tags']);

        $this->assertNotNull($readmeResult);
        $this->assertSame('README.md', $readmeResult['path']);
        $this->assertStringContainsString('Welcome to demo.', $readmeResult['content']);

        $this->assertContains('main', $this->data->branchList());
        $this->assertContains('v1.0.0', $this->data->tagList());

        $meta = $this->data->refMeta('main');
        $this->assertNotNull($meta);
        $this->assertSame($this->headSha, $meta['hash']);

        $this->assertFalse($this->data->isLoading('tree'));
        $this->assertFalse($this->data->isLoading('recursiveTree'));
        $this->assertFalse($this->data->isLoading('commits'));
        $this->assertFalse($this->data->isLoading('refs'));
        $this->assertFalse($this->data->isLoading('readme'));
    }

    public function testSynchronousCallsWhenCached(): void
    {
        // Populate cache
        $this->data->tree('', static fn () => null);
        $this->data->commits(static fn () => null);
        $this->data->refs(static fn () => null);
        $this->data->readme(static fn () => null);
        $this->drainAsync();

        $treeCalled = false;
        $this->data->tree('', function (array $entries) use (&$treeCalled): void {
            $treeCalled = true;
            $this->assertNotEmpty($entries);
        });
        $this->assertTrue($treeCalled, 'Cached tree callback must fire synchronously');

        $commitsCalled = false;
        $this->data->commits(function (array $commits) use (&$commitsCalled): void {
            $commitsCalled = true;
            $this->assertNotEmpty($commits);
        });
        $this->assertTrue($commitsCalled, 'Cached commits callback must fire synchronously');

        $refsCalled = false;
        $this->data->refs(function (array $refs) use (&$refsCalled): void {
            $refsCalled = true;
            $this->assertNotEmpty($refs['branches']);
        });
        $this->assertTrue($refsCalled, 'Cached refs callback must fire synchronously');

        $readmeCalled = false;
        $this->data->readme(function (?array $doc) use (&$readmeCalled): void {
            $readmeCalled = true;
            $this->assertNotNull($doc);
        });
        $this->assertTrue($readmeCalled, 'Cached readme callback must fire synchronously');
    }

    public function testQueuedDuplicateRequests(): void
    {
        $callback1 = false;
        $callback2 = false;

        $this->data->tree('', static function () use (&$callback1): void {
            $callback1 = true;
        });
        $this->data->tree('', static function () use (&$callback2): void {
            $callback2 = true;
        });

        $this->drainAsync();

        $this->assertTrue($callback1);
        $this->assertTrue($callback2);
    }

    public function testSwitchRefInvalidation(): void
    {
        // Populate cache on main
        $this->data->refs(static fn () => null);
        $this->drainAsync();
        $this->assertNotEmpty($this->data->branchList());

        $listenerCalled = false;
        $this->state->onRefChange(function (string $newRef) use (&$listenerCalled): void {
            $listenerCalled = true;
            $this->assertSame('feature-1', $newRef);
        });

        $this->state->subpath = 'src';
        $this->state->switchRef('feature-1');

        $this->assertTrue($listenerCalled);
        $this->assertSame('feature-1', $this->state->ref);
        $this->assertSame('', $this->state->subpath, 'subpath must reset on switchRef');
        $this->assertEmpty($this->data->branchList(), 'cached refs must be cleared after invalidation');
    }
}

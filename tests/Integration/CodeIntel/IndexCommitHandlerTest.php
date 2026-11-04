<?php

declare(strict_types=1);

namespace App\Tests\Integration\CodeIntel;

use App\CodeIntel\Application\IndexCommitHandler;
use App\CodeIntel\Application\Message\IndexCommit;
use App\CodeIntel\Application\Query\CodeIntelQuery;
use App\CodeIntel\Infrastructure\GitArchiveExtractor;
use App\CodeIntel\Infrastructure\Indexer\NativePhpIndexer;
use App\CodeIntel\Infrastructure\Indexer\ScipGoIndexer;
use App\CodeIntel\Infrastructure\Indexer\ScipPhpIndexer;
use App\CodeIntel\Infrastructure\Scip\ScipProtobufCodec;
use App\Repo\Domain\RepoDirectory;
use App\Repo\Domain\RepoName;
use Symfony\Component\Process\Process;

/**
 * Port of service_test.go: TestServiceIndexPHPCommit (legacy CodeIntelServiceTest::testServiceIndexPHPCommit).
 *
 * Indexes a real Git commit end-to-end through {@see IndexCommitHandler}: the commit tree is
 * exported via `git archive`, no external scip tool is on PATH (empty PATH injected), so the
 * native PHP fallback produces the index, which is then resolvable through {@see CodeIntelQuery}.
 */
final class IndexCommitHandlerTest extends CodeIntelTestCase
{
    public function testServiceIndexPHPCommit(): void
    {
        $repoDir = $this->createTempDir('git_repo_');

        $runGit = static function (array $args, string $cwd): string {
            $process = new Process(array_merge(['git'], $args), $cwd);
            $process->mustRun();

            return trim($process->getOutput());
        };

        $runGit(['init', '-b', 'main'], $repoDir);
        $runGit(['config', 'user.name', 'Test'], $repoDir);
        $runGit(['config', 'user.email', 'test@example.com'], $repoDir);

        mkdir($repoDir . '/src/Entity', 0755, true);
        mkdir($repoDir . '/src/Controller', 0755, true);

        file_put_contents($repoDir . '/composer.json', json_encode(['name' => 'acme/commit-test']));
        file_put_contents($repoDir . '/src/Entity/User.php', "<?php\nnamespace App\\Entity;\nclass User {}\n");
        file_put_contents(
            $repoDir . '/src/Controller/UserController.php',
            <<<'PHP'
<?php
namespace App\Controller;
use App\Entity\User;
class UserController {
    public function get(): User {
        return new User();
    }
}
PHP
        );

        $runGit(['add', '.'], $repoDir);
        $runGit(['commit', '-m', 'Initial commit'], $repoDir);
        $sha = $runGit(['rev-parse', 'HEAD'], $repoDir);
        self::assertNotEmpty($sha);

        $codec = new ScipProtobufCodec();
        $store = $this->makeStore($codec);

        // The commit under test lives at $repoDir, so map the repo name straight to it.
        $repoDirectory = new class($repoDir) implements RepoDirectory {
            public function __construct(private string $path) {}

            public function exists(RepoName $name): bool
            {
                return is_dir($this->path);
            }

            public function pathFor(RepoName $name): string
            {
                return $this->path;
            }
        };

        // Empty PATH => scip-go/scip-php are never found, forcing the native fallback.
        $handler = new IndexCommitHandler(
            $repoDirectory,
            new GitArchiveExtractor(),
            $store,
            [new ScipGoIndexer(''), new ScipPhpIndexer(''), new NativePhpIndexer($codec)],
        );

        $handler(new IndexCommit('acme/commit-test', $sha));

        $query = new CodeIntelQuery($store);
        self::assertTrue($query->has('acme/commit-test', $sha));

        // Resolve definition for the User return type in UserController.php (line 4, col 27).
        $def = $query->findDefinition('acme/commit-test', $sha, 'src/Controller/UserController.php', 4, 27);
        self::assertNotNull($def);
        self::assertSame('src/Entity/User.php', $def->path);
    }
}

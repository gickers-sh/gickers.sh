<?php

declare(strict_types=1);

namespace App\Tests\Service\CodeIntel;

use App\Model\CodeIntel\SymbolInfoModel;
use App\Service\CodeIntel\CodeIntelService;
use App\Service\CodeIntel\ScipProtobufParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class CodeIntelServiceTest extends TestCase
{
    private CodeIntelService $service;
    private ScipProtobufParser $parser;
    private string $storageDir;
    /** @var string[] */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        $this->parser = new ScipProtobufParser();
        $this->storageDir = $this->createTempDir('codeintel_storage_');
        $this->service = new CodeIntelService($this->parser, $this->storageDir);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }
        $this->tempDirs = [];
    }

    /**
     * Port of index_test.go: TestParseAndQuery
     * Tests encoding a binary .scip index with multi-file definitions, references,
     * 3-element and 4-element ranges, local symbols (ignored), and querying definitions and references.
     */
    public function testEncodeAndParseScipIndex(): void
    {
        $docs = [
            [
                'relative_path' => 'main.go',
                'language' => 'go',
                'occurrences' => [
                    [
                        'range' => [2, 5, 11], // 3-element range: line 2, col 5..11 (Definition)
                        'symbol' => 'example.com/test#Helper().',
                        'symbol_roles' => ScipProtobufParser::SYMBOL_ROLE_DEFINITION,
                    ],
                    [
                        'range' => [6, 1, 7], // 3-element range: line 6, col 1..7 (Reference)
                        'symbol' => 'example.com/test#Helper().',
                        'symbol_roles' => 0,
                    ],
                    [
                        'range' => [4, 1, 5],
                        'symbol' => 'local 1', // Local symbol: MUST be ignored
                        'symbol_roles' => 0,
                    ],
                    [
                        'range' => [15, 2, 17, 8], // 4-element range: line 15..17 (Definition)
                        'symbol' => 'example.com/test#MultiLine().',
                        'symbol_roles' => ScipProtobufParser::SYMBOL_ROLE_DEFINITION,
                    ],
                ],
            ],
            [
                'relative_path' => 'pkg/sub.go',
                'language' => 'go',
                'occurrences' => [
                    [
                        'range' => [10, 4, 10], // 3-element range (Reference)
                        'symbol' => 'example.com/test#Helper().',
                        'symbol_roles' => 0,
                    ],
                    [
                        'range' => [25, 0, 27, 4], // 4-element range (Reference)
                        'symbol' => 'example.com/test#MultiLine().',
                        'symbol_roles' => 0,
                    ],
                ],
            ],
        ];

        $scipBinary = $this->parser->encodeIndex($docs);
        self::assertNotEmpty($scipBinary);

        $repo = 'my/repo';
        $sha = 'a1b2c3d4e5f6';

        // 1. Store index and verify presence
        self::assertFalse($this->service->hasIndex($repo, $sha));
        $this->service->storeIndex($repo, $sha, $scipBinary);
        self::assertTrue($this->service->hasIndex($repo, $sha));
        self::assertFalse($this->service->hasIndex($repo, 'nonexistent'));

        // 2. Check occurrences in main.go (local 1 must be skipped)
        $occs = $this->service->getDocumentOccurrences($repo, $sha, 'main.go');
        self::assertCount(3, $occs);

        self::assertSame('example.com/test#Helper().', $occs[0]->symbol);
        self::assertTrue($occs[0]->isDefinition);
        self::assertSame(2, $occs[0]->startLine);
        self::assertSame(5, $occs[0]->startCol);
        self::assertSame(11, $occs[0]->endCol);

        self::assertSame('example.com/test#Helper().', $occs[1]->symbol);
        self::assertFalse($occs[1]->isDefinition);
        self::assertSame(6, $occs[1]->startLine);

        self::assertSame('example.com/test#MultiLine().', $occs[2]->symbol);
        self::assertTrue($occs[2]->isDefinition);
        self::assertSame(15, $occs[2]->startLine);
        self::assertSame(17, $occs[2]->endLine);

        // 3. Query definition from reference in main.go (line 6, col 3)
        $def = $this->service->findDefinition($repo, $sha, 'main.go', 6, 3);
        self::assertNotNull($def);
        self::assertSame('main.go', $def->path);
        self::assertSame(2, $def->line);
        self::assertSame(5, $def->col);

        // 4. Query definition from cross-file reference in pkg/sub.go (line 10, col 5)
        $defCross = $this->service->findDefinition($repo, $sha, 'pkg/sub.go', 10, 5);
        self::assertNotNull($defCross);
        self::assertSame('main.go', $defCross->path);
        self::assertSame(2, $defCross->line);
        self::assertSame(5, $defCross->col);

        // 5. Query multiline definition from pkg/sub.go (line 26, col 2)
        $defMulti = $this->service->findDefinition($repo, $sha, 'pkg/sub.go', 26, 2);
        self::assertNotNull($defMulti);
        self::assertSame('main.go', $defMulti->path);
        self::assertSame(15, $defMulti->line);
        self::assertSame(2, $defMulti->col);

        // 6. Query outside of any symbol returns null
        self::assertNull($this->service->findDefinition($repo, $sha, 'main.go', 0, 0));
        self::assertNull($this->service->symbolAt($repo, $sha, 'main.go', 0, 0));

        // 7. Query references from definition in main.go (line 2, col 7)
        $refs = $this->service->findReferences($repo, $sha, 'main.go', 2, 7);
        self::assertCount(2, $refs);
        self::assertSame('main.go', $refs[0]->path);
        self::assertSame(6, $refs[0]->line);
        self::assertSame('pkg/sub.go', $refs[1]->path);
        self::assertSame(10, $refs[1]->line);

        // 8. SymbolInfoModel lookup
        $symInfo = $this->service->getSymbolInfo($repo, $sha, 'example.com/test#Helper().');
        self::assertNotNull($symInfo);
        self::assertTrue($symInfo->hasDefinition());
        self::assertSame('main.go', $symInfo->primaryDefinition()?->path);
        self::assertSame(2, $symInfo->referenceCount());
    }

    /**
     * Port of php.go: IsPHPProject detection tests.
     */
    public function testIsPhpProject(): void
    {
        $dir = $this->createTempDir('php_detect_');

        // Empty directory
        self::assertFalse($this->service->isPhpProject($dir));

        // composer.json
        file_put_contents($dir.'/composer.json', '{}');
        self::assertTrue($this->service->isPhpProject($dir));
        unlink($dir.'/composer.json');

        // symfony.lock
        file_put_contents($dir.'/symfony.lock', '{}');
        self::assertTrue($this->service->isPhpProject($dir));
        unlink($dir.'/symfony.lock');

        // bin/console
        mkdir($dir.'/bin', 0755, true);
        file_put_contents($dir.'/bin/console', '#!/usr/bin/env php');
        self::assertTrue($this->service->isPhpProject($dir));
        unlink($dir.'/bin/console');

        // Only vendor or .git should be ignored
        mkdir($dir.'/vendor/bundle', 0755, true);
        file_put_contents($dir.'/vendor/bundle/Foo.php', '<?php');
        mkdir($dir.'/.git/hooks', 0755, true);
        file_put_contents($dir.'/.git/hooks/pre-commit.php', '<?php');
        self::assertFalse($this->service->isPhpProject($dir));

        // Actual application php file
        mkdir($dir.'/src', 0755, true);
        file_put_contents($dir.'/src/Kernel.php', '<?php');
        self::assertTrue($this->service->isPhpProject($dir));
    }

    /**
     * Port of php.go: IsSymfonyProject detection tests.
     */
    public function testIsSymfonyProject(): void
    {
        $dir = $this->createTempDir('symfony_detect_');

        self::assertFalse($this->service->isSymfonyProject($dir));

        file_put_contents($dir.'/composer.json', json_encode([
            'require' => ['symfony/framework-bundle' => '7.4.*'],
        ]));
        self::assertTrue($this->service->isSymfonyProject($dir));

        file_put_contents($dir.'/composer.json', json_encode([
            'require' => ['monolog/monolog' => '^3.0'],
        ]));
        self::assertFalse($this->service->isSymfonyProject($dir));

        file_put_contents($dir.'/symfony.lock', '{}');
        self::assertTrue($this->service->isSymfonyProject($dir));
    }

    /**
     * Port of php_test.go: TestSymfonyProjectIndexing
     * Tests indexing a PHP/Symfony project, resolving definitions and references across classes.
     */
    public function testSymfonyProjectIndexing(): void
    {
        $dir = $this->createTempDir('symfony_proj_');

        file_put_contents($dir.'/composer.json', json_encode([
            'name' => 'acme/symfony-demo',
            'require' => ['symfony/framework-bundle' => '7.4.*'],
        ]));

        mkdir($dir.'/src/Entity', 0755, true);
        mkdir($dir.'/src/Repository', 0755, true);
        mkdir($dir.'/src/Controller', 0755, true);

        // User.php
        file_put_contents($dir.'/src/Entity/User.php', <<<'PHP'
<?php
namespace App\Entity;

class User
{
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
PHP
        );

        // UserRepository.php
        file_put_contents($dir.'/src/Repository/UserRepository.php', <<<'PHP'
<?php
namespace App\Repository;

use App\Entity\User;

class UserRepository
{
    public function findOne(): ?User
    {
        return new User();
    }
}
PHP
        );

        // UserController.php
        file_put_contents($dir.'/src/Controller/UserController.php', <<<'PHP'
<?php
namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;

class UserController
{
    public function list(UserRepository $userRepo): User
    {
        return $userRepo->findOne();
    }
}
PHP
        );

        self::assertTrue($this->service->isPhpProject($dir));
        self::assertTrue($this->service->isSymfonyProject($dir));

        // Index project
        $docs = $this->service->indexPhpProject($dir);
        self::assertCount(3, $docs);

        $binary = $this->parser->encodeIndex($docs);
        $this->service->storeIndex('acme/symfony-demo', 'commit1', $binary);

        // Check Go To Definition: UserController list() parameter UserRepository
        // Check Go To Definition: UserController list() parameter UserRepository (0-indexed line 8)
        $defRepo = $this->service->findDefinition('acme/symfony-demo', 'commit1', 'src/Controller/UserController.php', 8, 27);
        self::assertNotNull($defRepo);
        self::assertSame('src/Repository/UserRepository.php', $defRepo->path);

        // Check Go To Definition: UserController list() return type User (0-indexed line 8)
        $defUser = $this->service->findDefinition('acme/symfony-demo', 'commit1', 'src/Controller/UserController.php', 8, 52);
        self::assertNotNull($defUser);
        self::assertSame('src/Entity/User.php', $defUser->path);

        // Check Find References: App\Entity\User
        $userSymbol = 'scip-php composer acme/symfony-demo dev App/Entity/User#';
        $refs = $this->service->findReferencesForSymbol('acme/symfony-demo', 'commit1', $userSymbol);
        self::assertGreaterThanOrEqual(2, count($refs));

        $foundRepoRef = false;
        $foundCtrlRef = false;
        foreach ($refs as $ref) {
            if ('src/Repository/UserRepository.php' === $ref->path) {
                $foundRepoRef = true;
            }
            if ('src/Controller/UserController.php' === $ref->path) {
                $foundCtrlRef = true;
            }
        }
        self::assertTrue($foundRepoRef);
        self::assertTrue($foundCtrlRef);
    }

    /**
     * Port of service_test.go: TestServiceIndexPHPCommit
     * Tests indexing a bare Git commit using git archive and resolving definitions.
     */
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

        mkdir($repoDir.'/src/Entity', 0755, true);
        mkdir($repoDir.'/src/Controller', 0755, true);

        file_put_contents($repoDir.'/composer.json', json_encode(['name' => 'acme/commit-test']));
        file_put_contents($repoDir.'/src/Entity/User.php', "<?php\nnamespace App\\Entity;\nclass User {}\n");
        file_put_contents($repoDir.'/src/Controller/UserController.php', <<<'PHP'
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

        // Execute commit indexing
        $success = $this->service->indexCommit($repoDir, 'acme/commit-test', $sha);
        self::assertTrue($success);
        self::assertTrue($this->service->hasIndex('acme/commit-test', $sha));

        // Resolve definition for User return type in UserController.php line 4, col 27
        $def = $this->service->findDefinition('acme/commit-test', $sha, 'src/Controller/UserController.php', 4, 27);
        self::assertNotNull($def);
        self::assertSame('src/Entity/User.php', $def->path);
    }

    private function createTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(6));
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $fileinfo) {
                if ($fileinfo->isDir()) {
                    @rmdir($fileinfo->getRealPath());
                } else {
                    @unlink($fileinfo->getRealPath());
                }
            }
            @rmdir($dir);
        } catch (\Throwable) {
            // Ignore cleanup errors
        }
    }
}

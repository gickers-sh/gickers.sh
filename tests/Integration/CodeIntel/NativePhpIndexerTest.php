<?php

declare(strict_types=1);

namespace App\Tests\Integration\CodeIntel;

use App\CodeIntel\Application\Query\CodeIntelQuery;
use App\CodeIntel\Infrastructure\Indexer\NativePhpIndexer;
use App\CodeIntel\Infrastructure\Scip\ScipProtobufCodec;

/**
 * Ports php.go / php_test.go project-detection and indexing tests to the native PHP indexer.
 */
final class NativePhpIndexerTest extends CodeIntelTestCase
{
    /**
     * Port of php.go: IsPHPProject detection tests.
     */
    public function testIsPhpProject(): void
    {
        $dir = $this->createTempDir('php_detect_');

        // Empty directory.
        self::assertFalse(NativePhpIndexer::isPhpProject($dir));

        // composer.json.
        file_put_contents($dir.'/composer.json', '{}');
        self::assertTrue(NativePhpIndexer::isPhpProject($dir));
        unlink($dir.'/composer.json');

        // symfony.lock.
        file_put_contents($dir.'/symfony.lock', '{}');
        self::assertTrue(NativePhpIndexer::isPhpProject($dir));
        unlink($dir.'/symfony.lock');

        // bin/console.
        mkdir($dir.'/bin', 0755, true);
        file_put_contents($dir.'/bin/console', '#!/usr/bin/env php');
        self::assertTrue(NativePhpIndexer::isPhpProject($dir));
        unlink($dir.'/bin/console');

        // Only vendor or .git should be ignored.
        mkdir($dir.'/vendor/bundle', 0755, true);
        file_put_contents($dir.'/vendor/bundle/Foo.php', '<?php');
        mkdir($dir.'/.git/hooks', 0755, true);
        file_put_contents($dir.'/.git/hooks/pre-commit.php', '<?php');
        self::assertFalse(NativePhpIndexer::isPhpProject($dir));

        // Actual application php file.
        mkdir($dir.'/src', 0755, true);
        file_put_contents($dir.'/src/Kernel.php', '<?php');
        self::assertTrue(NativePhpIndexer::isPhpProject($dir));
    }

    /**
     * Port of php.go: IsSymfonyProject detection tests.
     */
    public function testIsSymfonyProject(): void
    {
        $dir = $this->createTempDir('symfony_detect_');

        self::assertFalse(NativePhpIndexer::isSymfonyProject($dir));

        file_put_contents($dir.'/composer.json', json_encode([
            'require' => ['symfony/framework-bundle' => '7.4.*'],
        ]));
        self::assertTrue(NativePhpIndexer::isSymfonyProject($dir));

        file_put_contents($dir.'/composer.json', json_encode([
            'require' => ['monolog/monolog' => '^3.0'],
        ]));
        self::assertFalse(NativePhpIndexer::isSymfonyProject($dir));

        file_put_contents($dir.'/symfony.lock', '{}');
        self::assertTrue(NativePhpIndexer::isSymfonyProject($dir));
    }

    /**
     * Port of php_test.go: TestSymfonyProjectIndexing.
     * Indexes a PHP/Symfony project with the native indexer and resolves definitions and
     * references across classes.
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
        file_put_contents(
            $dir.'/src/Entity/User.php',
            <<<'PHP'
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
        file_put_contents(
            $dir.'/src/Repository/UserRepository.php',
            <<<'PHP'
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
        file_put_contents(
            $dir.'/src/Controller/UserController.php',
            <<<'PHP'
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

        self::assertTrue(NativePhpIndexer::isPhpProject($dir));
        self::assertTrue(NativePhpIndexer::isSymfonyProject($dir));

        $codec = new ScipProtobufCodec();
        $binary = (new NativePhpIndexer($codec))->index($dir);
        self::assertNotNull($binary);
        self::assertNotSame('', $binary);

        // One SCIP document per source file.
        $decoded = $codec->decodeIndex($binary);
        self::assertCount(3, $decoded['documents']);

        $store = $this->makeStore($codec);
        $store->store('acme/symfony-demo', 'commit1', $binary);
        $query = new CodeIntelQuery($store);

        // Go To Definition: UserController list() parameter UserRepository (0-indexed line 8).
        $defRepo = $query->findDefinition('acme/symfony-demo', 'commit1', 'src/Controller/UserController.php', 8, 27);
        self::assertNotNull($defRepo);
        self::assertSame('src/Repository/UserRepository.php', $defRepo->path);

        // Go To Definition: UserController list() return type User (0-indexed line 8).
        $defUser = $query->findDefinition('acme/symfony-demo', 'commit1', 'src/Controller/UserController.php', 8, 52);
        self::assertNotNull($defUser);
        self::assertSame('src/Entity/User.php', $defUser->path);

        // Find References: App\Entity\User.
        $userSymbol = 'scip-php composer acme/symfony-demo dev App/Entity/User#';
        $symInfo = $query->symbolInfo('acme/symfony-demo', 'commit1', $userSymbol);
        self::assertNotNull($symInfo);
        $refs = $symInfo->references;
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
}

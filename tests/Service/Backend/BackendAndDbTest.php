<?php

declare(strict_types=1);

namespace App\Tests\Service\Backend;

use App\Access\Domain\AccessLevel;
use App\Model\PullRequestModel;
use App\Service\Backend\BackendService;
use App\Service\Database\DatabaseManager;
use App\Service\Security\JwtManager;
use App\Service\Security\SsrfValidator;
use App\Service\Store\DataStore;
use App\Service\Webhook\WebhookDispatcher;
use PHPUnit\Framework\TestCase;

class BackendAndDbTest extends TestCase
{
    private DatabaseManager $databaseManager;
    private DataStore $dataStore;
    private SsrfValidator $ssrfValidator;
    private WebhookDispatcher $webhookDispatcher;
    private BackendService $backend;

    protected function setUp(): void
    {
        $this->databaseManager = new DatabaseManager('sqlite::memory:');
        $this->databaseManager->migrate();

        $this->dataStore = new DataStore($this->databaseManager);
        $this->ssrfValidator = new SsrfValidator();
        $this->webhookDispatcher = new WebhookDispatcher($this->dataStore, $this->ssrfValidator);
        $this->backend = new BackendService($this->dataStore, $this->webhookDispatcher);
    }

    public function testMigrations(): void
    {
        $db = new DatabaseManager('sqlite::memory:');
        $this->assertSame(0, $db->getCurrentVersion());

        $applied = $db->migrate();
        $this->assertSame(5, $applied);
        $this->assertSame(5, $db->getCurrentVersion());

        // Idempotent migration
        $appliedAgain = $db->migrate();
        $this->assertSame(0, $appliedAgain);
        $this->assertSame(5, $db->getCurrentVersion());

        // Verify tables exist in SQLite
        $pdo = $db->getConnection();
        $tables = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"
        )->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertContains('users', $tables);
        $this->assertContains('public_keys', $tables);
        $this->assertContains('repos', $tables);
        $this->assertContains('collabs', $tables);
        $this->assertContains('webhooks', $tables);
        $this->assertContains('webhook_deliveries', $tables);
        $this->assertContains('pull_requests', $tables);
        $this->assertContains('pull_request_comments', $tables);
        $this->assertContains('schema_migrations', $tables);
    }

    public function testUserAndPublicKeyManagement(): void
    {
        $alice = $this->backend->createUser('alice', isAdmin: true);
        $this->assertSame('alice', $alice->username);
        $this->assertTrue($alice->isAdmin);
        $this->assertNotNull($alice->id);

        $bob = $this->backend->createUser('bob', isAdmin: false);
        $this->assertSame('bob', $bob->username);
        $this->assertFalse($bob->isAdmin);

        // Public keys
        $key1 = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIG... alice@laptop';
        $key2 = 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQC... alice@desktop';
        $this->backend->addPublicKey('alice', $key1);
        $this->backend->addPublicKey('alice', $key2);

        $aliceFetched = $this->backend->getUser('alice');
        $this->assertNotNull($aliceFetched);
        $this->assertCount(2, $aliceFetched->publicKeys);
        $this->assertContains($key1, $aliceFetched->publicKeys);

        // Lookup by public key
        $userByKey = $this->backend->getUserByPublicKey($key1);
        $this->assertNotNull($userByKey);
        $this->assertSame('alice', $userByKey->username);

        // Remove a key
        $this->backend->removePublicKey('alice', $key1);
        $aliceUpdated = $this->backend->getUser('alice');
        $this->assertNotNull($aliceUpdated);
        $this->assertCount(1, $aliceUpdated->publicKeys);

        // Delete user
        $this->assertTrue($this->backend->deleteUser('bob'));
        $this->assertNull($this->backend->getUser('bob'));
    }

    public function testRepoAndForkMetadata(): void
    {
        $this->backend->createUser('owner', isAdmin: false);
        $this->backend->createUser('forker', isAdmin: false);

        $repoId = $this->backend->createRepo(
            name: 'my-project',
            projectName: 'My Project',
            description: 'A test project',
            private: false,
            mirror: false,
            hidden: false,
            ownerUsername: 'owner',
        );

        $this->assertGreaterThan(0, $repoId);
        $repo = $this->backend->getRepo('my-project');
        $this->assertNotNull($repo);
        $this->assertSame('my-project', $repo['name']);
        $this->assertSame('My Project', $repo['project_name']);

        // Fork repo
        $forkId = $this->backend->forkRepo('my-project', 'my-fork', 'forker');
        $this->assertGreaterThan(0, $forkId);

        $fork = $this->backend->getRepo('my-fork');
        $this->assertNotNull($fork);
        $this->assertSame(1, (int) $fork['is_fork']);
        $this->assertSame($repoId, (int) $fork['fork_id']);

        $forks = $this->backend->listForks('my-project');
        $this->assertCount(1, $forks);
        $this->assertSame('my-fork', $forks[0]['name']);
    }

    public function testCollaboratorAndAccessLevelResolution(): void
    {
        $this->backend->createUser('admin', isAdmin: true);
        $this->backend->createUser('owner', isAdmin: false);
        $this->backend->createUser('collab_rw', isAdmin: false);
        $this->backend->createUser('collab_ro', isAdmin: false);
        $this->backend->createUser('stranger', isAdmin: false);

        // Public repo
        $this->backend->createRepo('public-repo', 'Pub', 'desc', false, false, false, 'owner');
        // Private repo
        $this->backend->createRepo('private-repo', 'Priv', 'desc', true, false, false, 'owner');

        // Collaborators on private repo
        $this->backend->addCollaborator('private-repo', 'collab_rw', AccessLevel::ReadWriteAccess);
        $this->backend->addCollaborator('private-repo', 'collab_ro', AccessLevel::ReadOnlyAccess);

        // 1. Admin always has AdminAccess
        $this->assertSame(AccessLevel::AdminAccess, $this->backend->getAccessLevel('public-repo', 'admin'));
        $this->assertSame(AccessLevel::AdminAccess, $this->backend->getAccessLevel('private-repo', 'admin'));

        // 2. Owner has AdminAccess
        $this->assertSame(AccessLevel::AdminAccess, $this->backend->getAccessLevel('public-repo', 'owner'));
        $this->assertSame(AccessLevel::AdminAccess, $this->backend->getAccessLevel('private-repo', 'owner'));

        // 3. Collaborators on private repo
        $this->assertSame(AccessLevel::ReadWriteAccess, $this->backend->getAccessLevel('private-repo', 'collab_rw'));
        $this->assertSame(AccessLevel::ReadOnlyAccess, $this->backend->getAccessLevel('private-repo', 'collab_ro'));

        // 4. Stranger on private repo has NoAccess
        $this->assertSame(AccessLevel::NoAccess, $this->backend->getAccessLevel('private-repo', 'stranger'));
        $this->assertSame(AccessLevel::NoAccess, $this->backend->getAccessLevel('private-repo', null));

        // 5. Stranger on public repo has ReadOnlyAccess
        $this->assertSame(AccessLevel::ReadOnlyAccess, $this->backend->getAccessLevel('public-repo', 'stranger'));
        $this->assertSame(AccessLevel::ReadOnlyAccess, $this->backend->getAccessLevel('public-repo', null));

        // 6. Anonymous access level can be customized
        $this->backend->setAnonAccessLevel(AccessLevel::NoAccess);
        $this->assertSame(AccessLevel::NoAccess, $this->backend->getAccessLevel('public-repo', null));

        // 7. Non-existent repo
        $this->assertSame(AccessLevel::ReadWriteAccess, $this->backend->getAccessLevel('non-existent', 'owner'));
        $this->assertSame(AccessLevel::AdminAccess, $this->backend->getAccessLevel('non-existent', 'admin'));
    }

    public function testPullRequestLifecycleAndComments(): void
    {
        $this->backend->createUser('owner', isAdmin: false);
        $this->backend->createUser('author', isAdmin: false);
        $this->backend->createUser('reviewer', isAdmin: false);

        $this->backend->createRepo('target-repo', 'Target', 'desc', false, false, false, 'owner');
        $this->backend->addCollaborator('target-repo', 'reviewer', AccessLevel::ReadWriteAccess);

        // Create Pull Request #1
        $pr1 = $this->backend->createPullRequest(
            targetRepoName: 'target-repo',
            authorUsername: 'author',
            title: 'Add feature A',
            body: 'Detailed description of feature A',
            baseBranch: 'main',
            headBranch: 'feature-a',
        );

        $this->assertSame(1, $pr1->number);
        $this->assertSame('Add feature A', $pr1->title);
        $this->assertSame(PullRequestModel::STATE_OPEN, $pr1->state);
        $this->assertTrue($pr1->isOpen());
        $this->assertSame('author', $pr1->authorUsername);

        // Create Pull Request #2
        $pr2 = $this->backend->createPullRequest(
            targetRepoName: 'target-repo',
            authorUsername: 'author',
            title: 'Fix bug B',
            body: 'Bug fix description',
            baseBranch: 'main',
            headBranch: 'fix-b',
        );
        $this->assertSame(2, $pr2->number);

        // List PRs
        $allPrs = $this->backend->listPullRequests('target-repo');
        $this->assertCount(2, $allPrs);

        // Add comments
        $comment1 = $this->backend->addPullRequestComment(
            targetRepoName: 'target-repo',
            number: 1,
            authorUsername: 'reviewer',
            body: 'LGTM overall, please check line 42',
        );
        $this->assertSame('reviewer', $comment1->authorUsername);
        $this->assertSame('LGTM overall, please check line 42', $comment1->body);

        $inlineComment = $this->backend->addPullRequestComment(
            targetRepoName: 'target-repo',
            number: 1,
            authorUsername: 'reviewer',
            body: 'Typo in variable name here',
            commitSha: 'a1b2c3d4e5f6',
            filePath: 'src/main.php',
            line: 42,
        );
        $this->assertTrue($inlineComment->isLineComment());
        $this->assertSame(42, $inlineComment->line);
        $this->assertSame('src/main.php', $inlineComment->filePath);

        $comments = $this->backend->listPullRequestComments('target-repo', 1);
        $this->assertCount(2, $comments);

        // Update PR meta
        $updatedPr = $this->backend->updatePullRequest(
            targetRepoName: 'target-repo',
            number: 1,
            username: 'author',
            title: 'Add feature A (refined)',
            body: 'Updated description',
        );
        $this->assertSame('Add feature A (refined)', $updatedPr->title);

        // Merge PR #1
        $mergedPr = $this->backend->mergePullRequest(
            targetRepoName: 'target-repo',
            number: 1,
            mergedByUsername: 'reviewer',
            mergeCommit: 'commit_merge_sha_123',
        );
        $this->assertTrue($mergedPr->isMerged());
        $this->assertSame('reviewer', $mergedPr->mergedBy);

        // Attempting to merge again throws exception
        $this->expectException(\RuntimeException::class);
        $this->backend->mergePullRequest('target-repo', 1, 'reviewer');
    }

    public function testCloseAndReopenPullRequest(): void
    {
        $this->backend->createUser('owner', isAdmin: false);
        $this->backend->createUser('author', isAdmin: false);
        $this->backend->createRepo('repo-close', 'Close Repo', 'desc', false, false, false, 'owner');

        $pr = $this->backend->createPullRequest(
            'repo-close',
            'author',
            'Test close',
            'body',
            'main',
            'branch'
        );

        $this->assertTrue($pr->isOpen());

        // Close PR
        $closed = $this->backend->closePullRequest('repo-close', $pr->number, 'author');
        $this->assertTrue($closed->isClosed());

        // Reopen PR
        $reopened = $this->backend->reopenPullRequest('repo-close', $pr->number, 'author');
        $this->assertTrue($reopened->isOpen());
    }

    public function testJwtManagerHS256(): void
    {
        $jwt = new JwtManager('my-test-secret-key-12345');

        $token = $jwt->generateToken(
            sub: 'user_42',
            customClaims: ['role' => 'admin', 'scope' => 'repo:write'],
            ttl: 3600,
            algo: 'HS256',
        );

        $this->assertNotEmpty($token);
        $this->assertTrue($jwt->isValid($token));

        $claims = $jwt->verifyToken($token);
        $this->assertNotNull($claims);
        $this->assertSame('user_42', $claims['sub']);
        $this->assertSame('admin', $claims['role']);
        $this->assertSame('repo:write', $claims['scope']);
        $this->assertArrayHasKey('iat', $claims);
        $this->assertArrayHasKey('exp', $claims);
        $this->assertArrayHasKey('jti', $claims);

        // Reject wrong key
        $this->assertNull($jwt->verifyToken($token, 'wrong-key-secret'));

        // Reject tampered token
        $tampered = substr($token, 0, -4).'AAAA';
        $this->assertNull($jwt->verifyToken($tampered));

        // Reject expired token
        $expiredToken = $jwt->generateToken('user_42', [], ttl: -10);
        $this->assertNull($jwt->verifyToken($expiredToken));
        $this->assertFalse($jwt->isValid($expiredToken));
    }

    public function testJwtManagerEdDSA(): void
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('ext-sodium not available');
        }

        $keypair = JwtManager::generateEd25519KeyPair();
        $jwt = new JwtManager();

        $token = $jwt->generateToken(
            sub: 'ed25519_user',
            customClaims: ['iss' => 'soft-serve'],
            ttl: 1800,
            algo: 'EdDSA',
            key: $keypair['secretKey'],
        );

        $this->assertNotEmpty($token);
        $this->assertTrue($jwt->isValid($token, $keypair['publicKey'], 'EdDSA'));

        $claims = $jwt->verifyToken($token, $keypair['publicKey'], 'EdDSA');
        $this->assertNotNull($claims);
        $this->assertSame('ed25519_user', $claims['sub']);
        $this->assertSame('soft-serve', $claims['iss']);

        // Reject wrong public key
        $anotherKeypair = JwtManager::generateEd25519KeyPair();
        $this->assertNull($jwt->verifyToken($token, $anotherKeypair['publicKey'], 'EdDSA'));
    }

    public function testSsrfValidatorIpAndUrlBlocking(): void
    {
        $ssrf = new SsrfValidator();

        // 1. Blocked IPv4
        $this->assertFalse($ssrf->validateIp('127.0.0.1'));
        $this->assertFalse($ssrf->validateIp('127.1.2.3'));
        $this->assertFalse($ssrf->validateIp('10.0.0.1'));
        $this->assertFalse($ssrf->validateIp('10.254.254.254'));
        $this->assertFalse($ssrf->validateIp('172.16.0.1'));
        $this->assertFalse($ssrf->validateIp('172.31.255.254'));
        $this->assertFalse($ssrf->validateIp('192.168.1.1'));
        $this->assertFalse($ssrf->validateIp('169.254.169.254')); // AWS metadata
        $this->assertFalse($ssrf->validateIp('100.64.0.1')); // CGNAT
        $this->assertFalse($ssrf->validateIp('0.0.0.0'));
        $this->assertFalse($ssrf->validateIp('224.0.0.1')); // Multicast
        $this->assertFalse($ssrf->validateIp('240.0.0.1')); // Reserved

        // 2. Blocked IPv6
        $this->assertFalse($ssrf->validateIp('::1')); // Loopback
        $this->assertFalse($ssrf->validateIp('::')); // Unspecified
        $this->assertFalse($ssrf->validateIp('fe80::1')); // Link-local
        $this->assertFalse($ssrf->validateIp('fc00::1')); // ULA
        $this->assertFalse($ssrf->validateIp('ff02::1')); // Multicast

        // 3. IPv4-mapped IPv6
        $this->assertFalse($ssrf->validateIp('::ffff:127.0.0.1'));
        $this->assertFalse($ssrf->validateIp('::ffff:10.0.0.1'));
        $this->assertFalse($ssrf->validateIp('::ffff:169.254.169.254'));

        // 4. Allowed public IPs
        $this->assertTrue($ssrf->validateIp('8.8.8.8'));
        $this->assertTrue($ssrf->validateIp('1.1.1.1'));
        $this->assertTrue($ssrf->validateIp('93.184.216.34'));

        // 5. URL validation
        $this->assertFalse($ssrf->validateUrl('http://localhost/hook'));
        $this->assertFalse($ssrf->validateUrl('http://localhost:8080/hook'));
        $this->assertFalse($ssrf->validateUrl('http://app.localhost/hook'));
        $this->assertFalse($ssrf->validateUrl('http://127.0.0.1:8080/webhook'));
        $this->assertFalse($ssrf->validateUrl('http://169.254.169.254/latest/meta-data'));
        $this->assertFalse($ssrf->validateUrl('ftp://8.8.8.8/hook')); // Invalid scheme
        $this->assertFalse($ssrf->validateUrl('javascript:alert(1)'));
        $this->assertFalse($ssrf->validateUrl(''));

        $this->assertTrue($ssrf->validateUrl('https://8.8.8.8/webhook'));
        $this->assertTrue($ssrf->validateUrl('http://1.1.1.1/hook'));
    }

    public function testWebhookDispatcher(): void
    {
        $this->backend->createUser('owner', isAdmin: false);
        $repoId = $this->backend->createRepo('hook-repo', 'Hook Repo', 'desc', false, false, false, 'owner');

        // Signature generation
        $sig = $this->webhookDispatcher->computeSignature('{"event":"push"}', 'secret123');
        $this->assertStringStartsWith('sha256=', $sig);

        // Create webhook pointing to safe IP
        $webhook = $this->backend->createWebhook(
            repoName: 'hook-repo',
            url: 'https://8.8.8.8/webhook-target',
            secret: 'secret123',
            events: ['push'],
        );

        $deliveryId = $this->webhookDispatcher->dispatch(
            webhook: $webhook,
            event: 'push',
            payload: ['ref' => 'refs/heads/main', 'commits' => []],
            performHttp: false,
        );

        $this->assertNotEmpty($deliveryId);

        $deliveries = $this->dataStore->listDeliveries((int) $webhook->id);
        $this->assertCount(1, $deliveries);
        $this->assertSame(200, (int) $deliveries[0]['response_status']);
        $this->assertStringContainsString('X-Soft-Serve-Signature-256', $deliveries[0]['request_headers']);

        // Webhook pointing to SSRF-blocked target
        $blockedWebhook = $this->backend->createWebhook(
            repoName: 'hook-repo',
            url: 'http://169.254.169.254/latest/meta-data',
            secret: 'secret123',
            events: ['push'],
        );

        $blockedDeliveryId = $this->webhookDispatcher->dispatch(
            webhook: $blockedWebhook,
            event: 'push',
            payload: ['ref' => 'refs/heads/main'],
            performHttp: false,
        );

        $this->assertNotEmpty($blockedDeliveryId);
        $blockedDeliveries = $this->dataStore->listDeliveries((int) $blockedWebhook->id);
        $this->assertCount(1, $blockedDeliveries);
        $this->assertSame(0, (int) $blockedDeliveries[0]['response_status']);
        $this->assertStringContainsString('SSRF', $blockedDeliveries[0]['request_error']);
    }

    public function testRollbackAtVersionZeroThrows(): void
    {
        $db = new DatabaseManager('sqlite::memory:');
        $this->assertSame(0, $db->getCurrentVersion());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('there are no migrations to rollback');
        $db->rollback();
    }

    public function testRollbackAllMigrationsSequentially(): void
    {
        $db = new DatabaseManager('sqlite::memory:');
        $db->migrate();
        $this->assertSame(5, $db->getCurrentVersion());

        $pdo = $db->getConnection();
        $getTables = static fn () => $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"
        )->fetchAll(\PDO::FETCH_COLUMN);

        $getRepoCols = static fn () => $pdo->query(
            'PRAGMA table_info("repos")'
        )->fetchAll(\PDO::FETCH_COLUMN, 1);

        // 1. Rollback migration 5 (pull_requests)
        $rolled5 = $db->rollback();
        $this->assertSame(5, $rolled5);
        $this->assertSame(4, $db->getCurrentVersion());
        $tables = $getTables();
        $this->assertNotContains('pull_requests', $tables);
        $this->assertNotContains('pull_request_comments', $tables);
        $this->assertContains('repos', $tables);

        // 2. Rollback migration 4 (repo_fork)
        $rolled4 = $db->rollback();
        $this->assertSame(4, $rolled4);
        $this->assertSame(3, $db->getCurrentVersion());
        $cols = $getRepoCols();
        $this->assertNotContains('fork_id', $cols);

        // 3. Rollback migration 3 (migrate_lfs_objects)
        $rolled3 = $db->rollback();
        $this->assertSame(3, $rolled3);
        $this->assertSame(2, $db->getCurrentVersion());

        // 4. Rollback migration 2 (webhooks)
        $rolled2 = $db->rollback();
        $this->assertSame(2, $rolled2);
        $this->assertSame(1, $db->getCurrentVersion());
        $tables = $getTables();
        $this->assertNotContains('webhooks', $tables);
        $this->assertNotContains('webhook_events', $tables);
        $this->assertNotContains('webhook_deliveries', $tables);
        $this->assertContains('repos', $tables);
        $this->assertContains('users', $tables);

        // 5. Rollback migration 1 (create_tables)
        $rolled1 = $db->rollback();
        $this->assertSame(1, $rolled1);
        $this->assertSame(0, $db->getCurrentVersion());
        $tables = $getTables();
        $this->assertNotContains('repos', $tables);
        $this->assertNotContains('users', $tables);
        $this->assertNotContains('settings', $tables);
        $this->assertNotContains('public_keys', $tables);
        $this->assertNotContains('collabs', $tables);

        // 6. Rolling back now throws
        try {
            $db->rollback();
            $this->fail('Expected RuntimeException on rollback at version 0');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('there are no migrations to rollback', $e->getMessage());
        }

        // 7. Re-running migrate() re-applies all 5 cleanly
        $reapplied = $db->migrate();
        $this->assertSame(5, $reapplied);
        $this->assertSame(5, $db->getCurrentVersion());
        $tables = $getTables();
        $this->assertContains('users', $tables);
        $this->assertContains('pull_requests', $tables);
    }

    public function testAdminRollbackCommand(): void
    {
        $db = new DatabaseManager('sqlite::memory:');
        $cmd = new \App\Command\AdminRollbackCommand($db);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($cmd);

        // When DB is at version 0, rollback fails
        $exitCode = $tester->execute([]);
        $this->assertSame(\Symfony\Component\Console\Command\Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Rollback failed: there are no migrations to rollback', $tester->getDisplay());

        // Migrate to version 5
        $db->migrate();
        $this->assertSame(5, $db->getCurrentVersion());

        // Rollback via command
        $exitCode = $tester->execute([]);
        $this->assertSame(\Symfony\Component\Console\Command\Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Database rollback completed (version 5 rolled back).', $tester->getDisplay());
        $this->assertSame(4, $db->getCurrentVersion());
    }
}

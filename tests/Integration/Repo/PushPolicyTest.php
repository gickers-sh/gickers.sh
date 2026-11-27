<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repo;

use App\Identity\Domain\Model\SshKey;
use App\Repo\Application\EvaluatePushPolicy;
use App\Repo\Domain\Git\GitRepository;
use App\Repo\Infrastructure\Git\CommitSignatureVerifier;
use App\Repo\Infrastructure\Signature\AccountTrustSource;
use App\Tests\GitTestHelper;
use Symfony\Component\Process\Process;

/**
 * Push-time governance enforcement by {@see EvaluatePushPolicy}: default-branch
 * protection, no-delete / no-force / admins-only rules, and signed-commit
 * enforcement against account-linked SSH keys.
 */
final class PushPolicyTest extends RepoUseCaseTestCase
{
    private const ZERO = '0000000000000000000000000000000000000000';

    private function policy(): EvaluatePushPolicy
    {
        $verifier = new CommitSignatureVerifier(new AccountTrustSource($this->userStore));

        return new EvaluatePushPolicy($this->repoStore, $this->userStore, $this->storage, $this->accessResolver, $verifier);
    }

    private function line(string $old, string $new, string $ref): string
    {
        return sprintf("%s %s %s\n", $old, $new, $ref);
    }

    private function headSha(GitRepository $repo, string $ref = 'refs/heads/main'): string
    {
        $p = new Process(['git', 'rev-parse', $ref], $repo->path);
        $p->mustRun();

        return trim($p->getOutput());
    }

    public function testDefaultBranchDeletionIsRejectedWithoutAnyRule(): void
    {
        $owner = $this->user('owner');
        $this->repoRow('proj', $owner);
        $repo = $this->initRepoOnDisk('proj');
        $head = $this->headSha($repo);

        $violations = ($this->policy())('proj', 'owner', $this->line($head, self::ZERO, 'refs/heads/main'));

        self::assertCount(1, $violations);
        self::assertStringContainsString('default branch', $violations[0]);
    }

    public function testNoDeleteRuleRejectsDeletionOfMatchingRef(): void
    {
        $owner = $this->user('owner');
        $row = $this->repoRow('proj', $owner);
        $repo = $this->initRepoOnDisk('proj');
        $feature = GitTestHelper::writeFile($repo, 'feature', 'a.txt', "a\n", 'change', 'Owner', 'owner@gickers.sh');

        $row->protectBranch('feature', noForce: false, noDelete: true, adminsOnly: false, now: $this->clock->now());
        $this->repoStore->save($row);

        $violations = ($this->policy())('proj', 'owner', $this->line($feature, self::ZERO, 'refs/heads/feature'));

        self::assertCount(1, $violations);
        self::assertStringContainsString('deleting', $violations[0]);
    }

    public function testNoForceRuleRejectsNonFastForwardButAllowsFastForward(): void
    {
        $owner = $this->user('owner');
        $row = $this->repoRow('proj', $owner);
        $repo = $this->initRepoOnDisk('proj');
        $base = $this->headSha($repo);
        $forward = GitTestHelper::writeFile($repo, 'main', 'b.txt', "b\n", 'change', 'Owner', 'owner@gickers.sh'); // child of base
        $divergent = GitTestHelper::writeFile($repo, 'orphan', 'c.txt', "c\n", 'change', 'Owner', 'owner@gickers.sh'); // no ancestry to base

        $row->protectBranch('main', noForce: true, noDelete: false, adminsOnly: false, now: $this->clock->now());
        $this->repoStore->save($row);
        $policy = $this->policy();

        $ff = $policy('proj', 'owner', $this->line($base, $forward, 'refs/heads/main'));
        self::assertSame([], $ff);

        $force = $policy('proj', 'owner', $this->line($base, $divergent, 'refs/heads/main'));
        self::assertCount(1, $force);
        self::assertStringContainsString('force', $force[0]);
    }

    public function testAdminsOnlyRejectsNonAdminButAllowsOwner(): void
    {
        $owner = $this->user('owner');
        $this->user('bob');
        $row = $this->repoRow('proj', $owner);
        $repo = $this->initRepoOnDisk('proj');
        $base = $this->headSha($repo, 'refs/heads/main');
        $next = GitTestHelper::writeFile($repo, 'main', 'd.txt', "d\n", 'change', 'Owner', 'owner@gickers.sh');

        $row->protectBranch('main', noForce: false, noDelete: false, adminsOnly: true, now: $this->clock->now());
        $this->repoStore->save($row);
        $policy = $this->policy();

        $blocked = $policy('proj', 'bob', $this->line($base, $next, 'refs/heads/main'));
        self::assertCount(1, $blocked);
        self::assertStringContainsString('administrators', $blocked[0]);

        $allowed = $policy('proj', 'owner', $this->line($base, $next, 'refs/heads/main'));
        self::assertSame([], $allowed);
    }

    public function testSignedCommitsRequirementRejectsUnsignedAndAcceptsSignedByAccountKey(): void
    {
        $owner = $this->user('owner');
        $row = $this->repoRow('proj', $owner);
        $repo = $this->initRepoOnDisk('proj');
        $base = $this->headSha($repo);

        $keyDir = $this->dataPath.'/signer';
        mkdir($keyDir, 0700, true);
        $keyPath = $keyDir.'/id';
        (new Process(['ssh-keygen', '-t', 'ed25519', '-f', $keyPath, '-N', '', '-C', 'owner@gickers.sh', '-q']))->mustRun();
        $pub = trim((string) file_get_contents($keyPath.'.pub'));
        $owner->addPublicKey(SshKey::parse($pub), $this->clock->now());
        $this->userStore->save($owner);

        $row->setRequireSignedCommits(true, $this->clock->now());
        $this->repoStore->save($row);
        $policy = $this->policy();

        $unsigned = $this->newCommit($repo, $base, 'u.txt', "u\n", null);
        $rejected = $policy('proj', 'owner', $this->line($base, $unsigned, 'refs/heads/main'));
        self::assertNotEmpty($rejected);
        self::assertStringContainsString('not signed', $rejected[0]);

        $signed = $this->newCommit($repo, $base, 's.txt', "s\n", $keyPath.'.pub');
        $accepted = $policy('proj', 'owner', $this->line($base, $signed, 'refs/heads/main'));
        self::assertSame([], $accepted);
    }

    /**
     * Builds a commit object in the bare repo (child of $parent) without moving any
     * ref, optionally SSH-signed with $signingKey. Mirrors the pre-receive quarantine
     * state: the new objects exist but refs still point at the old tip.
     */
    private function newCommit(GitRepository $repo, string $parent, string $file, string $content, ?string $signingKey): string
    {
        $env = [
            'GIT_AUTHOR_NAME' => 'Owner',
            'GIT_AUTHOR_EMAIL' => 'owner@gickers.sh',
            'GIT_COMMITTER_NAME' => 'Owner',
            'GIT_COMMITTER_EMAIL' => 'owner@gickers.sh',
        ];

        $blob = new Process(['git', 'hash-object', '-w', '--stdin'], $repo->path, input: $content);
        $blob->mustRun();
        $blobHash = trim($blob->getOutput());

        $index = (string) tempnam(sys_get_temp_dir(), 'idx_');
        @unlink($index);
        $indexEnv = $env + ['GIT_INDEX_FILE' => $index];
        (new Process(['git', 'read-tree', $parent], $repo->path, $indexEnv))->mustRun();
        (new Process(['git', 'update-index', '--add', '--cacheinfo', sprintf('100644,%s,%s', $blobHash, $file)], $repo->path, $indexEnv))->mustRun();
        $treeProc = new Process(['git', 'write-tree'], $repo->path, $indexEnv);
        $treeProc->mustRun();
        $tree = trim($treeProc->getOutput());
        @unlink($index);

        $args = ['git'];
        if (null !== $signingKey) {
            $args = array_merge($args, ['-c', 'gpg.format=ssh', '-c', 'user.signingkey='.$signingKey]);
        }
        $args = array_merge($args, ['commit-tree', $tree, '-p', $parent, '-m', 'change']);
        if (null !== $signingKey) {
            $args[] = '-S';
        }
        $commit = new Process($args, $repo->path, $env);
        $commit->mustRun();

        return trim($commit->getOutput());
    }
}

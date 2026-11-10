<?php

declare(strict_types=1);

namespace App\Repo\Application;

use App\Access\Application\AccessResolver;
use App\Identity\Domain\UserStore;
use App\Repo\Domain\Git\GitRepository;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Git\CommitSignatureVerifier;
use App\Repo\Infrastructure\Git\GitCommand;
use App\Repo\Infrastructure\Git\Revision;
use App\Shared\Domain\Exception\InvalidInput;

/**
 * Evaluates a push against a repository's governance: branch-protection rules
 * (no-force, no-delete, admins-only), default-branch deletion, and signed-commit
 * enforcement. Called by the pre-receive hook with the authenticated actor from
 * `SOFT_SERVE_USERNAME` (set server-side, never by the client), so admin checks and
 * identity are trustworthy.
 */
final readonly class EvaluatePushPolicy
{
    private const ZERO = '0000000000000000000000000000000000000000';

    public function __construct(
        private RepoStore $repoStore,
        private UserStore $userStore,
        private BareRepositoryStorage $storage,
        private AccessResolver $accessResolver,
        private CommitSignatureVerifier $verifier,
    ) {
    }

    /**
     * Parses receive-pack pre-receive stdin (`<old> <new> <ref>` per line) and returns
     * the list of rejection reasons. An empty list means the push is allowed.
     *
     * @return list<string>
     */
    public function __invoke(string $repoName, ?string $username, string $stdin): array
    {
        try {
            $name = RepoName::fromInput($repoName);
        } catch (InvalidInput) {
            return [];
        }

        $repo = $this->repoStore->byName($name);
        if (null === $repo || !$this->storage->exists($name)) {
            return [];
        }

        $rules = $repo->branchProtections();
        $requireSigned = $repo->requireSignedCommits();
        $updates = $this->parse($stdin);

        $hasDelete = false;
        foreach ($updates as [, $new]) {
            if (self::ZERO === $new) {
                $hasDelete = true;
                break;
            }
        }

        // Fast path: nothing to enforce. The default branch can never be deleted, so a
        // deletion always needs the full check even without configured rules.
        if ([] === $rules && !$requireSigned && !$hasDelete) {
            return [];
        }

        $gitRepo = $this->storage->open($name);
        $actor = (null !== $username && '' !== $username) ? $this->userStore->byUsername($username) : null;
        $isAdmin = $this->accessResolver->levelFor($actor, $name, $repo)->isAdmin();
        $defaultRef = 'refs/heads/'.$gitRepo->defaultBranch;

        $violations = [];
        foreach ($updates as [$old, $new, $ref]) {
            $isDelete = self::ZERO === $new;
            $isCreate = self::ZERO === $old;

            if ($isDelete && $ref === $defaultRef) {
                $violations[] = sprintf('%s: refusing to delete the default branch', $ref);

                continue;
            }
            foreach ($rules as $rule) {
                if (!$rule->matches($ref)) {
                    continue;
                }
                if ($rule->adminsOnly() && !$isAdmin) {
                    $violations[] = sprintf('%s: only administrators may update this protected ref', $ref);
                }
                if ($rule->noDelete() && $isDelete) {
                    $violations[] = sprintf('%s: deleting this protected ref is not allowed', $ref);
                }
                if ($rule->noForce() && !$isCreate && !$isDelete && !$this->isFastForward($gitRepo, $old, $new)) {
                    $violations[] = sprintf('%s: non-fast-forward (force) updates are not allowed', $ref);
                }
            }

            if ($requireSigned && !$isDelete) {
                foreach ($this->newCommits($gitRepo, $new) as $commit) {
                    if (!$this->verifier->verify($gitRepo, $commit)->isVerified()) {
                        $violations[] = sprintf('%s: commit %s is not signed by a verified key', $ref, substr($commit, 0, 10));
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function parse(string $stdin): array
    {
        $updates = [];
        foreach (explode("\n", trim($stdin)) as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            if (3 !== \count($parts)) {
                continue;
            }
            [$old, $new, $ref] = $parts;
            if (1 !== preg_match('/^[0-9a-f]{40}$/', $old) || 1 !== preg_match('/^[0-9a-f]{40}$/', $new)) {
                continue;
            }
            $updates[] = [$old, $new, $ref];
        }

        return $updates;
    }

    private function isFastForward(GitRepository $repo, string $old, string $new): bool
    {
        $process = GitCommand::run($repo->path, ['merge-base', '--is-ancestor', new Revision($old), new Revision($new)]);

        return 0 === $process->getExitCode();
    }

    /**
     * New commits introduced by `$new` that are not reachable from any existing ref.
     *
     * @return list<string>
     */
    private function newCommits(GitRepository $repo, string $new): array
    {
        if (1 !== preg_match('/^[0-9a-f]{40}$/', $new)) {
            return [];
        }
        // `$new` is a validated 40-hex SHA from receive-pack; options follow it so it is
        // passed positionally without `--end-of-options`.
        $process = GitCommand::run($repo->path, ['rev-list', $new, '--not', '--all']);
        if (!$process->isSuccessful()) {
            return [];
        }

        $commits = [];
        foreach (explode("\n", trim($process->getOutput())) as $sha) {
            $sha = trim($sha);
            if (1 === preg_match('/^[0-9a-f]{40}$/', $sha)) {
                $commits[] = $sha;
            }
        }

        return $commits;
    }
}

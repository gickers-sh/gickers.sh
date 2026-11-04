<?php

declare(strict_types=1);

namespace App\PullRequest\Application;

use App\Access\Application\AccessGuard;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\PullRequest\Domain\Event\PullRequestOpened;
use App\PullRequest\Domain\Model\PullRequest;
use App\PullRequest\Domain\PullRequestStore;
use App\PullRequest\Infrastructure\Git\PullRequestGit;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\InvalidInput;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Opens a pull request against a target repository (Go `pkg/backend/pr.go`
 * `CreatePullRequest`). The head may live in another repository
 * (`[REPO:]BRANCH`), enabling the fork workflow. The number is reserved
 * atomically so concurrent opens never collide.
 */
final readonly class OpenPullRequest
{
    public function __construct(
        private AccessGuard $accessGuard,
        private PullRequestStore $pullRequestStore,
        private RepoStore $repoStore,
        private PullRequestGit $git,
        private ClockInterface $clock,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(
        ?User $actor,
        RepoName|string $repo,
        string $title,
        string $description,
        string $head,
        ?string $base = null,
    ): PullRequest {
        if (null === $actor) {
            throw new AccessDenied('unauthorized');
        }

        $targetName = $repo instanceof RepoName ? $repo : RepoName::fromInput($repo);
        $target = $this->accessGuard->assertRepo($actor, $targetName, AccessLevel::ReadOnlyAccess);
        \assert($target instanceof Repo);

        $head = trim($head);
        if ('' === $head) {
            throw new InvalidInput('--head branch must be specified');
        }

        $headRepo = $target;
        $headBranch = $head;
        $colon = strpos($head, ':');
        if (false !== $colon) {
            $headRepoName = RepoName::fromInput(substr($head, 0, $colon));
            $headBranch = substr($head, $colon + 1);
            $resolvedHead = $this->accessGuard->assertRepo($actor, $headRepoName, AccessLevel::ReadOnlyAccess);
            \assert($resolvedHead instanceof Repo);
            $headRepo = $resolvedHead;
        }

        $headBranch = $this->stripRefsHeads(trim($headBranch));
        if ('' === $headBranch) {
            throw new InvalidInput('head ref cannot be empty');
        }

        $baseBranch = $this->stripRefsHeads(trim((string) $base));
        if ('' === $baseBranch) {
            $baseBranch = $this->stripRefsHeads($this->git->defaultBranch($target));
        }

        if ($headRepo->id() === $target->id() && $baseBranch === $headBranch) {
            throw new InvalidInput('base and head branches cannot be identical in the same repository');
        }

        $baseCommit = $this->git->resolveBranchCommit($target, $baseBranch);
        if (null === $baseCommit) {
            throw new InvalidInput(\sprintf('base branch %s does not exist', $baseBranch));
        }

        $headCommit = $this->git->resolveBranchCommit($headRepo, $headBranch);
        if (null === $headCommit) {
            throw new InvalidInput(\sprintf('head branch %s does not exist', $headBranch));
        }

        // Commits resolved before reserving the number so a bad ref never burns one.
        $number = $this->repoStore->reservePullRequestNumber($target);
        $this->git->fetchPullRef($target, $headRepo, $headBranch, $number);

        $title = trim($title);
        if ('' === $title) {
            $title = \sprintf('Pull Request #%d', $number);
        }

        $pullRequest = new PullRequest(
            $target,
            $number,
            $title,
            trim($description),
            $baseBranch,
            $baseCommit,
            $headRepo,
            $headBranch,
            $headCommit,
            $actor,
            $this->clock->now(),
        );
        $this->pullRequestStore->save($pullRequest);

        $this->eventDispatcher->dispatch(
            new PullRequestOpened($target->id(), $pullRequest->id(), $number, $actor->id()),
        );

        return $pullRequest;
    }

    private function stripRefsHeads(string $ref): string
    {
        return str_starts_with($ref, 'refs/heads/') ? substr($ref, \strlen('refs/heads/')) : $ref;
    }
}

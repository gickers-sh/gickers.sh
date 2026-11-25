<?php

declare(strict_types=1);

namespace App\Deploy\Application;

use App\Deploy\Application\Message\RunDeploy;
use App\Deploy\Domain\DeployStatus;
use App\Deploy\Domain\DeployStore;
use App\Deploy\Domain\Model\DeployEnvironment;
use App\Deploy\Domain\Model\DeployRelease;
use App\Deploy\Domain\ReleaseStatus;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\RepoDirectory;
use App\Repo\Domain\RepoName;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Domain\Exception\NotFound;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class TriggerDeploy
{
    public function __construct(
        private DeployAccessResolver $accessResolver,
        private DeployStore $deployStore,
        private RepoDirectory $repoDirectory,
        private GitRepositoryReader $gitReader,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(?User $actor, RepoName|string $repoName, string $ref): DeployRelease
    {
        $repo = $this->accessResolver->assertCanManageDeploy($actor, $repoName, $ref);
        $environment = $this->deployStore->environmentByRef($repo, $ref);
        if (null === $environment) {
            throw new NotFound(sprintf('deployment environment "%s" not found (enable it first with `repo deploy enable`)', $ref));
        }

        if (!$environment->isEnabled()) {
            throw new InvalidInput(sprintf('deployment environment "%s" is disabled', $environment->refName()));
        }

        if (!$this->repoDirectory->exists($repo->name())) {
            throw new NotFound('repository not found on disk');
        }

        $gitRepo = $this->gitReader->openRepository($this->repoDirectory->pathFor($repo->name()));
        try {
            $commits = $this->gitReader->getCommits($gitRepo, $environment->refName(), 1);
        } catch (\Throwable) {
            $commits = [];
        }

        if ([] === $commits) {
            throw new InvalidInput(sprintf('ref "%s" does not resolve to any commit in repository "%s"', $environment->refName(), $repo->nameString()));
        }

        $commitSha = $commits[0]->hash;

        return $this->queueForEnvironment($environment, $commitSha, $actor, coalesceIfActive: false, triggerSource: 'manual');
    }

    /**
     * Queues a deployment release for an environment while enforcing at most 1 active deployment
     * (Queued or Building) at a time for a given (repo + ref).
     *
     * - When $coalesceIfActive is false (manual CLI trigger/redeploy): throws Conflict if a build is already Building,
     *   or returns/updates the existing Queued release if it covers the request.
     * - When $coalesceIfActive is true (git push event / boot reconciliation): if a release is already Queued, updates
     *   its target commit in-place instead of spawning a second concurrent deployment; if a release is currently Building,
     *   skips or coalesces so at most one deployment runs at a time.
     */
    public function queueForEnvironment(
        DeployEnvironment $environment,
        string $commitSha,
        ?User $actor,
        bool $coalesceIfActive = false,
        string $triggerSource = 'push',
    ): DeployRelease {
        $actor ??= $environment->repo()->owner();
        $now = $this->clock->now();
        $activeReleases = $this->deployStore->activeReleasesForEnvironment($environment);

        if ([] !== $activeReleases) {
            $active = $activeReleases[0];

            // If a release is still Queued (waiting for worker), coalesce into it in-place
            if (ReleaseStatus::Queued === $active->status()) {
                if ($active->commitSha() !== $commitSha) {
                    $active->updateQueuedTarget($commitSha, $actor, $now);
                    $this->deployStore->saveRelease($active);
                }

                return $active;
            }

            // A release is currently Building
            if (!$coalesceIfActive) {
                throw new Conflict(sprintf('a deployment (#%d, commit %s) is already in progress for "%s@%s"', $active->id(), substr($active->commitSha(), 0, 7), $environment->repo()->nameString(), $environment->refName()));
            }

            return $active;
        }

        $environment->setStatus(DeployStatus::Queued, $now);
        $this->deployStore->saveEnvironment($environment);

        $release = new DeployRelease(
            environment: $environment,
            commitSha: $commitSha,
            triggeredBy: $actor,
            startedAt: $now,
        );
        $release->appendLog(sprintf("==> Triggered by @%s (%s)\n", $actor->username(), $triggerSource));
        $this->deployStore->saveRelease($release);

        $this->messageBus->dispatch(new RunDeploy($release->id()));

        return $release;
    }
}

<?php

declare(strict_types=1);

namespace App\Deploy\Application;

use App\Deploy\Application\Message\RunDeploy;
use App\Deploy\Domain\DeployStatus;
use App\Deploy\Domain\DeployStore;
use App\Deploy\Domain\Model\DeployRelease;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\RepoDirectory;
use App\Repo\Domain\RepoName;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
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
        $now = $this->clock->now();

        $environment->setStatus(DeployStatus::Queued, $now);
        $this->deployStore->saveEnvironment($environment);

        $release = new DeployRelease(
            environment: $environment,
            commitSha: $commitSha,
            triggeredBy: $actor,
            startedAt: $now,
        );
        $this->deployStore->saveRelease($release);

        $this->messageBus->dispatch(new RunDeploy($release->id()));

        return $release;
    }
}

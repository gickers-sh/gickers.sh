<?php

declare(strict_types=1);

namespace App\Deploy\Application;

use App\Deploy\Domain\DeployRefType;
use App\Deploy\Domain\DeployStore;
use App\Deploy\Domain\Model\DeployEnvironment;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\RepoDirectory;
use App\Repo\Domain\RepoName;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Shared\Domain\Exception\InvalidInput;
use Psr\Clock\ClockInterface;

final readonly class EnableEnvironment
{
    public function __construct(
        private DeployAccessResolver $accessResolver,
        private DeployStore $deployStore,
        private RepoDirectory $repoDirectory,
        private GitRepositoryReader $gitReader,
        private TriggerDeploy $triggerDeploy,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(?User $actor, RepoName|string $repoName, ?string $ref = null, bool $triggerIfExists = true): DeployEnvironment
    {
        $repo = $this->accessResolver->assertCanManageDeploy($actor, $repoName, $ref);
        $now = $this->clock->now();

        $gitRepo = $this->repoDirectory->exists($repo->name())
            ? $this->gitReader->openRepository($this->repoDirectory->pathFor($repo->name()))
            : null;

        if (null === $ref || '' === trim($ref)) {
            $ref = null !== $gitRepo ? $gitRepo->defaultBranch : 'main';
        }

        $refName = DeployEnvironment::normalizeRefName($ref);

        // Re-assert with resolved refName
        $this->accessResolver->assertCanManageDeploy($actor, $repo->name(), $refName);

        $environment = $this->deployStore->environmentByRef($repo, $refName);
        if (null === $environment) {
            $quota = $this->accessResolver->effectiveQuota($repo, $refName);
            $existing = $this->deployStore->environmentsForRepo($repo);
            $activeCount = \count(array_filter($existing, static fn (DeployEnvironment $e): bool => $e->isEnabled()));
            if ($activeCount >= $quota->maxEnvironments) {
                throw new InvalidInput(sprintf(
                    'repository "%s" already has %d active deployment environments (quota max-envs: %d)',
                    $repo->nameString(),
                    $activeCount,
                    $quota->maxEnvironments,
                ));
            }

            $refType = DeployRefType::Branch;
            if (str_starts_with(trim($ref), 'refs/tags/')) {
                $refType = DeployRefType::Tag;
            } elseif (null !== $gitRepo) {
                $refs = $this->gitReader->getRefs($gitRepo);
                if (\in_array($refName, $refs->tags, true) && !\in_array($refName, $refs->branches, true)) {
                    $refType = DeployRefType::Tag;
                }
            }

            $environment = new DeployEnvironment($repo, $refName, $refType, $now);
            $this->deployStore->saveEnvironment($environment);
        } elseif (!$environment->isEnabled()) {
            $quota = $this->accessResolver->effectiveQuota($repo, $refName);
            $existing = $this->deployStore->environmentsForRepo($repo);
            $activeCount = \count(array_filter($existing, static fn (DeployEnvironment $e): bool => $e->isEnabled()));
            if ($activeCount >= $quota->maxEnvironments) {
                throw new InvalidInput(sprintf(
                    'repository "%s" already has %d active deployment environments (quota max-envs: %d)',
                    $repo->nameString(),
                    $activeCount,
                    $quota->maxEnvironments,
                ));
            }
            $environment->setEnabled(true, $now);
            $this->deployStore->saveEnvironment($environment);
        }

        if ($triggerIfExists && null !== $gitRepo) {
            $commits = [];
            try {
                $commits = $this->gitReader->getCommits($gitRepo, $refName, 1);
            } catch (\Throwable) {
                $commits = [];
            }

            if ([] !== $commits) {
                ($this->triggerDeploy)($actor, $repo->name(), $refName);
            }
        }

        return $environment;
    }
}

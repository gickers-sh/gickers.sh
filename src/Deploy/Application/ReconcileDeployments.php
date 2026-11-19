<?php

declare(strict_types=1);

namespace App\Deploy\Application;

use App\Deploy\Application\Message\RunDeploy;
use App\Deploy\Domain\DeployStatus;
use App\Deploy\Domain\DeployStore;
use App\Deploy\Domain\Model\DeployEnvironment;
use App\Deploy\Domain\Model\DeployRelease;
use App\Deploy\Domain\ReverseProxyRouter;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoDirectory;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\NotFound;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ReconcileDeployments
{
    public function __construct(
        private DeployStore $deployStore,
        private ReverseProxyRouter $proxyRouter,
        private TriggerDeploy $triggerDeploy,
        private RepoStore $repoStore,
        private RepoDirectory $repoDirectory,
        private GitRepositoryReader $gitReader,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Reconciles reverse proxy (Caddy) configuration for all active deployment domains.
     * When $reload is false, writes configuration to disk without reloading the Caddy daemon.
     */
    public function reconcileCaddyRoutes(bool $reload = true): int
    {
        $domains = $this->deployStore->allActiveDomains();
        $this->proxyRouter->syncDomains($domains, reload: $reload);

        return count($domains);
    }

    /**
     * Directly queues a redeploy for an enabled environment without actor permission check.
     * Used by system events (e.g. boot reconciliation).
     */
    public function redeployEnvironment(DeployEnvironment $environment, ?User $actor = null): ?DeployRelease
    {
        if (!$environment->isEnabled()) {
            return null;
        }

        $repo = $environment->repo();
        if (!$this->repoDirectory->exists($repo->name())) {
            return null;
        }

        try {
            $gitRepo = $this->gitReader->openRepository($this->repoDirectory->pathFor($repo->name()));
            $commits = $this->gitReader->getCommits($gitRepo, $environment->refName(), 1);
        } catch (\Throwable) {
            $commits = [];
        }

        if ([] === $commits) {
            return null;
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

    /**
     * Reconciles running deployment environments on server boot.
     * Only environments that are enabled and currently in Running status are queued for redeployment.
     *
     * @return list<DeployRelease>
     */
    public function reconcileOnBoot(): array
    {
        $releases = [];
        foreach ($this->deployStore->allActiveEnvironments() as $env) {
            if (DeployStatus::Running === $env->status()) {
                $release = $this->redeployEnvironment($env, null);
                if (null !== $release) {
                    $releases[] = $release;
                }
            }
        }

        return $releases;
    }

    /**
     * Redeploys active deployment environments.
     *
     * @return list<DeployRelease>
     */
    public function redeploy(
        ?User $actor,
        RepoName|string|null $repoName = null,
        ?string $ref = null,
        bool $all = false,
    ): array {
        if ($all) {
            if (null === $actor || !$actor->isAdmin()) {
                throw new AccessDenied('only server administrators can redeploy all active deployments across the server');
            }

            $releases = [];
            foreach ($this->deployStore->allActiveEnvironments() as $env) {
                try {
                    $releases[] = ($this->triggerDeploy)($actor, $env->repo()->name(), $env->refName());
                } catch (\Throwable) {
                    // Continue with other environments
                }
            }

            return $releases;
        }

        if (null === $repoName) {
            throw new \InvalidArgumentException('repository name is required unless --all is specified');
        }

        $repoName = $repoName instanceof RepoName ? $repoName : RepoName::fromInput($repoName);
        $repo = $this->repoStore->byName($repoName);
        if (null === $repo) {
            throw new NotFound('repository not found');
        }

        if (null !== $ref && '' !== trim($ref)) {
            return [($this->triggerDeploy)($actor, $repo->name(), trim($ref))];
        }

        $releases = [];
        foreach ($this->deployStore->environmentsForRepo($repo) as $env) {
            if ($env->isEnabled()) {
                $releases[] = ($this->triggerDeploy)($actor, $repo->name(), $env->refName());
            }
        }

        return $releases;
    }
}

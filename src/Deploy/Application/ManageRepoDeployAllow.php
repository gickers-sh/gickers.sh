<?php

declare(strict_types=1);

namespace App\Deploy\Application;

use App\Access\Application\AccessGuard;
use App\Deploy\Domain\DeployStore;
use App\Deploy\Domain\Model\RepoDeployConfig;
use App\Deploy\Domain\Model\RepoDeployRefRule;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Shared\Domain\Exception\NotFound;
use Psr\Clock\ClockInterface;

final readonly class ManageRepoDeployAllow
{
    public function __construct(
        private AccessGuard $accessGuard,
        private RepoStore $repoStore,
        private DeployStore $deployStore,
        private DeployAccessResolver $accessResolver,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(
        ?User $actor,
        RepoName|string $repoName,
        ?bool $allowed = null,
        ?string $refPattern = null,
    ): bool {
        $this->accessGuard->assertServerAdmin($actor);

        $name = $repoName instanceof RepoName ? $repoName : RepoName::fromInput($repoName);
        $repo = $this->repoStore->byName($name);
        if (null === $repo) {
            throw new NotFound('repository not found');
        }

        $now = $this->clock->now();

        if (null !== $refPattern && '' !== trim($refPattern)) {
            $pattern = trim($refPattern);
            $rule = $this->deployStore->refRuleByPattern($repo, $pattern);
            if (null !== $allowed) {
                if (null === $rule) {
                    $rule = new RepoDeployRefRule($repo, $pattern, $now);
                }
                $rule->setAllowedOverride($allowed, $now);
                $this->deployStore->saveRefRule($rule);
            }

            return $this->accessResolver->isRepoRefDeployAllowed($repo, $pattern);
        }

        $config = $this->deployStore->configForRepo($repo);
        if (null !== $allowed) {
            if (null === $config) {
                $config = new RepoDeployConfig($repo, $now);
            }
            $config->setAllowedOverride($allowed, $now);
            $this->deployStore->saveConfig($config);
        }

        return $this->accessResolver->isRepoRefDeployAllowed($repo);
    }
}

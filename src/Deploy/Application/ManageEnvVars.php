<?php

declare(strict_types=1);

namespace App\Deploy\Application;

use App\Deploy\Domain\DeployStore;
use App\Deploy\Domain\EnvVarEncryptor;
use App\Deploy\Domain\Model\DeployEnvVar;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\Exception\NotFound;
use Psr\Clock\ClockInterface;

final readonly class ManageEnvVars
{
    public function __construct(
        private DeployAccessResolver $accessResolver,
        private DeployStore $deployStore,
        private EnvVarEncryptor $encryptor,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, string> $pairs
     */
    public function set(?User $actor, RepoName|string $repoName, string $ref, array $pairs): void
    {
        $repo = $this->accessResolver->assertCanManageDeploy($actor, $repoName, $ref);
        $environment = $this->deployStore->environmentByRef($repo, $ref);
        if (null === $environment) {
            throw new NotFound(sprintf('deployment environment "%s" not found', $ref));
        }

        $now = $this->clock->now();
        foreach ($pairs as $name => $value) {
            $normalizedName = DeployEnvVar::normalizeName($name);
            $encrypted = $this->encryptor->encrypt($value);
            $existing = $this->deployStore->envVarByName($environment, $normalizedName);
            if (null !== $existing) {
                $existing->updateEncryptedValue($encrypted, $now);
                $this->deployStore->saveEnvVar($existing);
            } else {
                $envVar = new DeployEnvVar($environment, $normalizedName, $encrypted, $now);
                $this->deployStore->saveEnvVar($envVar);
            }
        }
    }

    public function get(?User $actor, RepoName|string $repoName, string $ref, string $key, bool $reveal = false): string
    {
        $repo = $this->accessResolver->assertCanManageDeploy($actor, $repoName, $ref);
        $environment = $this->deployStore->environmentByRef($repo, $ref);
        if (null === $environment) {
            throw new NotFound(sprintf('deployment environment "%s" not found', $ref));
        }

        $envVar = $this->deployStore->envVarByName($environment, $key);
        if (null === $envVar) {
            throw new NotFound(sprintf('environment variable "%s" not found', $key));
        }

        $plain = $this->encryptor->decrypt($envVar->encryptedValue());
        if ($reveal) {
            return $plain;
        }

        return '********';
    }

    public function unset(?User $actor, RepoName|string $repoName, string $ref, string $key): void
    {
        $repo = $this->accessResolver->assertCanManageDeploy($actor, $repoName, $ref);
        $environment = $this->deployStore->environmentByRef($repo, $ref);
        if (null === $environment) {
            throw new NotFound(sprintf('deployment environment "%s" not found', $ref));
        }

        $envVar = $this->deployStore->envVarByName($environment, $key);
        if (null === $envVar) {
            throw new NotFound(sprintf('environment variable "%s" not found', $key));
        }

        $this->deployStore->removeEnvVar($envVar);
    }

    /**
     * @return list<DeployEnvVar>
     */
    public function list(?User $actor, RepoName|string $repoName, string $ref): array
    {
        $repo = $this->accessResolver->assertCanManageDeploy($actor, $repoName, $ref);
        $environment = $this->deployStore->environmentByRef($repo, $ref);
        if (null === $environment) {
            throw new NotFound(sprintf('deployment environment "%s" not found', $ref));
        }

        return $this->deployStore->envVarsForEnvironment($environment);
    }
}

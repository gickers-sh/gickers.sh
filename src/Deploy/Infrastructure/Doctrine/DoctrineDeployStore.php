<?php

declare(strict_types=1);

namespace App\Deploy\Infrastructure\Doctrine;

use App\Deploy\Domain\DeployStore;
use App\Deploy\Domain\Model\DeployDomain;
use App\Deploy\Domain\Model\DeployEnvironment;
use App\Deploy\Domain\Model\DeployEnvVar;
use App\Deploy\Domain\Model\DeployRelease;
use App\Deploy\Domain\Model\RepoDeployConfig;
use App\Deploy\Domain\Model\RepoDeployRefRule;
use App\Deploy\Domain\ReleaseStatus;
use App\Repo\Domain\Model\Repo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(DeployStore::class)]
final readonly class DoctrineDeployStore implements DeployStore
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function configForRepo(Repo $repo): ?RepoDeployConfig
    {
        return $this->em->getRepository(RepoDeployConfig::class)->findOneBy(['repo' => $repo]);
    }

    public function saveConfig(RepoDeployConfig $config): void
    {
        $this->em->persist($config);
        $this->em->flush();
    }

    public function refRulesForRepo(Repo $repo): array
    {
        return $this->em->getRepository(RepoDeployRefRule::class)->findBy(['repo' => $repo], ['id' => 'ASC']);
    }

    public function refRuleByPattern(Repo $repo, string $refPattern): ?RepoDeployRefRule
    {
        return $this->em->getRepository(RepoDeployRefRule::class)->findOneBy(['repo' => $repo, 'refPattern' => $refPattern]);
    }

    public function saveRefRule(RepoDeployRefRule $rule): void
    {
        $this->em->persist($rule);
        $this->em->flush();
    }

    public function removeRefRule(RepoDeployRefRule $rule): void
    {
        $this->em->remove($rule);
        $this->em->flush();
    }

    public function environmentById(int $id): ?DeployEnvironment
    {
        return $this->em->getRepository(DeployEnvironment::class)->find($id);
    }

    public function environmentByRef(Repo $repo, string $refName): ?DeployEnvironment
    {
        $normalized = DeployEnvironment::normalizeRefName($refName);

        return $this->em->getRepository(DeployEnvironment::class)->findOneBy(['repo' => $repo, 'refName' => $normalized]);
    }

    public function environmentsForRepo(Repo $repo): array
    {
        return $this->em->getRepository(DeployEnvironment::class)->findBy(['repo' => $repo], ['refName' => 'ASC']);
    }

    public function environmentsForRepoId(int $repoId): array
    {
        return $this->em->getRepository(DeployEnvironment::class)->findBy(['repo' => $repoId], ['refName' => 'ASC']);
    }

    public function allActiveEnvironments(): array
    {
        /** @var list<DeployEnvironment> $environments */
        $environments = $this->em->createQueryBuilder()
            ->select('e')
            ->from(DeployEnvironment::class, 'e')
            ->where('e.enabled = true')
            ->orderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $environments;
    }

    public function saveEnvironment(DeployEnvironment $environment): void
    {
        $this->em->persist($environment);
        $this->em->flush();
    }

    public function removeEnvironment(DeployEnvironment $environment): void
    {
        $this->em->remove($environment);
        $this->em->flush();
    }

    public function domainByName(string $domain): ?DeployDomain
    {
        $normalized = DeployDomain::normalizeDomain($domain);

        return $this->em->getRepository(DeployDomain::class)->findOneBy(['domain' => $normalized]);
    }

    public function domainForEnvironment(DeployEnvironment $environment, string $domain): ?DeployDomain
    {
        $normalized = DeployDomain::normalizeDomain($domain);

        return $this->em->getRepository(DeployDomain::class)->findOneBy([
            'environment' => $environment,
            'domain' => $normalized,
        ]);
    }

    public function domainsForRepo(Repo $repo): array
    {
        /** @var list<DeployDomain> $domains */
        $domains = $this->em->createQueryBuilder()
            ->select('d')
            ->from(DeployDomain::class, 'd')
            ->join('d.environment', 'e')
            ->where('e.repo = :repo')
            ->setParameter('repo', $repo)
            ->orderBy('d.domain', 'ASC')
            ->getQuery()
            ->getResult();

        return $domains;
    }

    public function allActiveDomains(): array
    {
        /** @var list<DeployDomain> $domains */
        $domains = $this->em->createQueryBuilder()
            ->select('d')
            ->from(DeployDomain::class, 'd')
            ->join('d.environment', 'e')
            ->where('e.enabled = true')
            ->orderBy('d.domain', 'ASC')
            ->getQuery()
            ->getResult();

        return $domains;
    }

    public function saveDomain(DeployDomain $domain): void
    {
        $this->em->persist($domain);
        $this->em->flush();
    }

    public function removeDomain(DeployDomain $domain): void
    {
        $domain->environment()->removeDomainElement($domain);
        $this->em->remove($domain);
        $this->em->flush();
    }

    public function envVarByName(DeployEnvironment $environment, string $name): ?DeployEnvVar
    {
        $normalized = DeployEnvVar::normalizeName($name);

        return $this->em->getRepository(DeployEnvVar::class)->findOneBy([
            'environment' => $environment,
            'name' => $normalized,
        ]);
    }

    public function envVarsForEnvironment(DeployEnvironment $environment): array
    {
        return $this->em->getRepository(DeployEnvVar::class)->findBy(
            ['environment' => $environment],
            ['name' => 'ASC'],
        );
    }

    public function saveEnvVar(DeployEnvVar $envVar): void
    {
        $this->em->persist($envVar);
        $this->em->flush();
    }

    public function removeEnvVar(DeployEnvVar $envVar): void
    {
        $envVar->environment()->removeEnvVarElement($envVar);
        $this->em->remove($envVar);
        $this->em->flush();
    }

    public function releaseById(int $id): ?DeployRelease
    {
        return $this->em->getRepository(DeployRelease::class)->find($id);
    }

    public function latestReleaseForEnvironment(DeployEnvironment $environment): ?DeployRelease
    {
        $release = $this->em->getRepository(DeployRelease::class)->findOneBy(
            ['environment' => $environment],
            ['id' => 'DESC'],
        );
        if (null !== $release) {
            $this->em->refresh($release);
        }

        return $release;
    }

    public function refreshRelease(DeployRelease $release): void
    {
        $this->em->refresh($release);
    }

    public function releasesForEnvironment(DeployEnvironment $environment, int $limit = 10): array
    {
        return $this->em->getRepository(DeployRelease::class)->findBy(
            ['environment' => $environment],
            ['id' => 'DESC'],
            $limit,
        );
    }

    public function activeReleasesForEnvironment(DeployEnvironment $environment): array
    {
        /** @var list<DeployRelease> $releases */
        $releases = $this->em->createQueryBuilder()
            ->select('r')
            ->from(DeployRelease::class, 'r')
            ->where('r.environment = :env')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('env', $environment)
            ->setParameter('statuses', [ReleaseStatus::Queued, ReleaseStatus::Building])
            ->orderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $releases;
    }

    public function saveRelease(DeployRelease $release): void
    {
        $this->em->persist($release);
        $this->em->flush();
    }
}

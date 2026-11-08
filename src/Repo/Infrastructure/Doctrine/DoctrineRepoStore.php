<?php

declare(strict_types=1);

namespace App\Repo\Infrastructure\Doctrine;

use App\Identity\Domain\Model\User;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @extends ServiceEntityRepository<Repo>
 */
#[AsAlias(RepoStore::class)]
final class DoctrineRepoStore extends ServiceEntityRepository implements RepoStore
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Repo::class);
    }

    public function byId(int $id): ?Repo
    {
        return $this->find($id);
    }

    public function byName(RepoName $name): ?Repo
    {
        return $this->findOneBy(['name' => $name->value]);
    }

    /**
     * @return list<Repo>
     */
    public function all(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }

    /**
     * @return list<Repo>
     */
    public function ownedBy(User $user): array
    {
        return $this->findBy(['owner' => $user], ['name' => 'ASC']);
    }

    /**
     * @return list<Repo>
     */
    public function forksOf(Repo $repo): array
    {
        return $this->findBy(['forkOf' => $repo], ['name' => 'ASC']);
    }

    public function save(Repo $repo): void
    {
        $this->getEntityManager()->persist($repo);
        $this->getEntityManager()->flush();
    }

    public function remove(Repo $repo): void
    {
        $this->getEntityManager()->remove($repo);
        $this->getEntityManager()->flush();
    }
}

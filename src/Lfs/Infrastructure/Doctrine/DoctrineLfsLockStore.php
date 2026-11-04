<?php

declare(strict_types=1);

namespace App\Lfs\Infrastructure\Doctrine;

use App\Lfs\Domain\LfsLockStore;
use App\Lfs\Domain\Model\LfsLock;
use App\Repo\Domain\Model\Repo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @extends ServiceEntityRepository<LfsLock>
 */
#[AsAlias(LfsLockStore::class)]
final class DoctrineLfsLockStore extends ServiceEntityRepository implements LfsLockStore
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LfsLock::class);
    }

    public function byId(Repo $repo, int $id): ?LfsLock
    {
        return $this->findOneBy(['repo' => $repo, 'id' => $id]);
    }

    public function byPath(Repo $repo, string $path): ?LfsLock
    {
        return $this->findOneBy(['repo' => $repo, 'path' => $path]);
    }

    /**
     * @return list<LfsLock>
     */
    public function page(Repo $repo, int $cursor, int $limit): array
    {
        $offset = ($cursor - 1) * $limit;

        return $this->findBy(['repo' => $repo], ['id' => 'ASC'], $limit, max(0, $offset));
    }

    public function save(LfsLock $lock): void
    {
        $this->getEntityManager()->persist($lock);
        $this->getEntityManager()->flush();
    }

    public function remove(LfsLock $lock): void
    {
        $this->getEntityManager()->remove($lock);
        $this->getEntityManager()->flush();
    }
}

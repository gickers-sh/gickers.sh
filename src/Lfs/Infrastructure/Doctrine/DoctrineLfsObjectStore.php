<?php

declare(strict_types=1);

namespace App\Lfs\Infrastructure\Doctrine;

use App\Lfs\Domain\LfsObjectStore;
use App\Lfs\Domain\Model\LfsObject;
use App\Lfs\Domain\Oid;
use App\Repo\Domain\Model\Repo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @extends ServiceEntityRepository<LfsObject>
 */
#[AsAlias(LfsObjectStore::class)]
final class DoctrineLfsObjectStore extends ServiceEntityRepository implements LfsObjectStore
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LfsObject::class);
    }

    public function byOid(Repo $repo, Oid $oid): ?LfsObject
    {
        return $this->findOneBy(['repo' => $repo, 'oid' => $oid->value]);
    }

    public function save(LfsObject $object): void
    {
        $this->getEntityManager()->persist($object);
        $this->getEntityManager()->flush();
    }

    public function remove(LfsObject $object): void
    {
        $this->getEntityManager()->remove($object);
        $this->getEntityManager()->flush();
    }
}

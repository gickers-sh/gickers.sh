<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @extends ServiceEntityRepository<User>
 */
#[AsAlias(UserStore::class)]
final class DoctrineUserStore extends ServiceEntityRepository implements UserStore
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function byId(int $id): ?User
    {
        return $this->find($id);
    }

    public function byUsername(string $username): ?User
    {
        return $this->findOneBy(['username' => User::normalizeUsername($username)]);
    }

    public function byFingerprint(string $fingerprint): ?User
    {
        $users = $this->allByFingerprint($fingerprint);

        return $users[0] ?? null;
    }

    public function byFingerprintAndUsername(string $fingerprint, string $username): ?User
    {
        try {
            $normalized = User::normalizeUsername($username);
        } catch (\App\Shared\Domain\Exception\InvalidInput) {
            return null;
        }

        return $this->createQueryBuilder('u')
            ->innerJoin('u.publicKeys', 'pk')
            ->where('pk.fingerprint = :fingerprint')
            ->andWhere('u.username = :username')
            ->setParameter('fingerprint', $fingerprint)
            ->setParameter('username', $normalized)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<User>
     */
    public function allByFingerprint(string $fingerprint): array
    {
        /** @var list<User> */
        return $this->createQueryBuilder('u')
            ->innerJoin('u.publicKeys', 'pk')
            ->where('pk.fingerprint = :fingerprint')
            ->setParameter('fingerprint', $fingerprint)
            ->orderBy('pk.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<User>
     */
    public function all(): array
    {
        return $this->findBy([], ['username' => 'ASC']);
    }

    public function count(array $criteria = []): int
    {
        return parent::count($criteria);
    }

    public function save(User $user): void
    {
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function remove(User $user): void
    {
        $this->getEntityManager()->remove($user);
        $this->getEntityManager()->flush();
    }
}

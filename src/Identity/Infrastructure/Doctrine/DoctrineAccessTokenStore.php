<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\AccessTokenStore;
use App\Identity\Domain\Model\AccessToken;
use App\Identity\Domain\Model\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @extends ServiceEntityRepository<AccessToken>
 */
#[AsAlias(AccessTokenStore::class)]
final class DoctrineAccessTokenStore extends ServiceEntityRepository implements AccessTokenStore
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessToken::class);
    }

    public function byHash(string $tokenHash): ?AccessToken
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    public function byIdForUser(int $id, User $user): ?AccessToken
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    public function byNameForUser(string $name, User $user): ?AccessToken
    {
        return $this->findOneBy(['name' => $name, 'user' => $user]);
    }

    /**
     * @return list<AccessToken>
     */
    public function forUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'ASC']);
    }

    public function save(AccessToken $token): void
    {
        $this->getEntityManager()->persist($token);
        $this->getEntityManager()->flush();
    }

    public function remove(AccessToken $token): void
    {
        $this->getEntityManager()->remove($token);
        $this->getEntityManager()->flush();
    }
}

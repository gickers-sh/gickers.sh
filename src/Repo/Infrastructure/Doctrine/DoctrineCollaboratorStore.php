<?php

declare(strict_types=1);

namespace App\Repo\Infrastructure\Doctrine;

use App\Identity\Domain\Model\User;
use App\Repo\Domain\CollaboratorStore;
use App\Repo\Domain\Model\Collaborator;
use App\Repo\Domain\Model\Repo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @extends ServiceEntityRepository<Collaborator>
 */
#[AsAlias(CollaboratorStore::class)]
final class DoctrineCollaboratorStore extends ServiceEntityRepository implements CollaboratorStore
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Collaborator::class);
    }

    /**
     * @phpstan-param mixed $repo
     * @phpstan-param mixed $user
     * @phpstan-param mixed $lockVersion
     */
    public function find(mixed $repo, mixed $user = null, mixed $lockVersion = null): ?Collaborator
    {
        if ($repo instanceof Repo && $user instanceof User) {
            return $this->findOneBy(['repo' => $repo, 'user' => $user]);
        }

        if (is_int($repo) || is_string($repo)) {
            $lockMode = is_int($user) || $user instanceof \Doctrine\DBAL\LockMode ? $user : null;

            /** @var ?Collaborator */
            return parent::find($repo, $lockMode, $lockVersion);
        }

        return null;
    }

    /**
     * @return list<Collaborator>
     */
    public function forRepo(Repo $repo): array
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.user', 'u')
            ->where('c.repo = :repo')
            ->setParameter('repo', $repo)
            ->orderBy('u.username', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(Collaborator $collaborator): void
    {
        $this->getEntityManager()->persist($collaborator);
        $this->getEntityManager()->flush();
    }

    public function remove(Collaborator $collaborator): void
    {
        $this->getEntityManager()->remove($collaborator);
        $this->getEntityManager()->flush();
    }
}

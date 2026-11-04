<?php

declare(strict_types=1);

namespace App\Webhook\Infrastructure\Doctrine;

use App\Repo\Domain\Model\Repo;
use App\Webhook\Domain\Model\Delivery;
use App\Webhook\Domain\Model\Webhook;
use App\Webhook\Domain\WebhookStore;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @extends ServiceEntityRepository<Webhook>
 */
#[AsAlias(WebhookStore::class)]
final class DoctrineWebhookStore extends ServiceEntityRepository implements WebhookStore
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Webhook::class);
    }

    public function byId(int $id): ?Webhook
    {
        return $this->find($id);
    }

    public function forRepo(Repo $repo): array
    {
        return $this->findBy(['repo' => $repo], ['id' => 'ASC']);
    }

    public function forRepoId(int $repoId): array
    {
        return $this->findBy(['repo' => $repoId], ['id' => 'ASC']);
    }

    public function findByUrl(Repo $repo, string $url): ?Webhook
    {
        return $this->findOneBy(['repo' => $repo, 'url' => $url]);
    }

    public function save(Webhook $webhook): void
    {
        $this->getEntityManager()->persist($webhook);
        $this->getEntityManager()->flush();
    }

    public function remove(Webhook $webhook): void
    {
        $this->getEntityManager()->remove($webhook);
        $this->getEntityManager()->flush();
    }

    public function saveDelivery(Delivery $delivery): void
    {
        $this->getEntityManager()->persist($delivery);
        $this->getEntityManager()->flush();
    }
}

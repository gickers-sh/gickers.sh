<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\Model\WebLoginTicket;
use App\Identity\Domain\WebLoginTicketStore;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @extends ServiceEntityRepository<WebLoginTicket>
 */
#[AsAlias(WebLoginTicketStore::class)]
final class DoctrineWebLoginTicketStore extends ServiceEntityRepository implements WebLoginTicketStore
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebLoginTicket::class);
    }

    public function save(WebLoginTicket $ticket): void
    {
        $em = $this->getEntityManager();
        $em->persist($ticket);
        $em->flush();
    }

    public function byHash(string $hash): ?WebLoginTicket
    {
        return $this->findOneBy(['tokenHash' => $hash]);
    }
}

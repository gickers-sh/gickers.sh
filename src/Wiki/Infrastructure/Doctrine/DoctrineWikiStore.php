<?php

declare(strict_types=1);

namespace App\Wiki\Infrastructure\Doctrine;

use App\Wiki\Domain\Model\WikiPage;
use App\Wiki\Domain\WikiSlug;
use App\Wiki\Domain\WikiStore;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @extends ServiceEntityRepository<WikiPage>
 */
#[AsAlias(WikiStore::class)]
final class DoctrineWikiStore extends ServiceEntityRepository implements WikiStore
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WikiPage::class);
    }

    public function byId(int $id): ?WikiPage
    {
        return $this->find($id);
    }

    public function bySlug(WikiSlug $slug): ?WikiPage
    {
        return $this->findOneBy(['slug' => $slug->value]);
    }

    public function all(): array
    {
        return $this->findBy([], ['position' => 'ASC', 'category' => 'ASC', 'title' => 'ASC', 'slug' => 'ASC']);
    }

    public function save(WikiPage $page): void
    {
        $this->getEntityManager()->persist($page);
        $this->getEntityManager()->flush();
    }

    public function remove(WikiPage $page): void
    {
        $this->getEntityManager()->remove($page);
        $this->getEntityManager()->flush();
    }
}

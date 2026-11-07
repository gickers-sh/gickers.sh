<?php

declare(strict_types=1);

namespace App\PullRequest\Infrastructure\Doctrine;

use App\PullRequest\Domain\Model\Comment;
use App\PullRequest\Domain\Model\PullRequest;
use App\PullRequest\Domain\PullRequestStatus;
use App\PullRequest\Domain\PullRequestStore;
use App\Repo\Domain\Model\Repo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @extends ServiceEntityRepository<PullRequest>
 */
#[AsAlias(PullRequestStore::class)]
final class DoctrinePullRequestStore extends ServiceEntityRepository implements PullRequestStore
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PullRequest::class);
    }

    public function byId(int $id): ?PullRequest
    {
        return $this->find($id);
    }

    public function byNumber(Repo $repo, int $number): ?PullRequest
    {
        return $this->findOneBy(['repo' => $repo, 'number' => $number]);
    }

    /**
     * @return list<PullRequest>
     */
    public function listForRepo(Repo $repo, ?PullRequestStatus $status = null): array
    {
        $criteria = ['repo' => $repo];
        if (null !== $status) {
            $criteria['status'] = $status;
        }

        return $this->findBy($criteria, ['number' => 'DESC']);
    }

    public function save(PullRequest $pullRequest): void
    {
        $this->getEntityManager()->persist($pullRequest);
        $this->getEntityManager()->flush();
    }

    public function remove(PullRequest $pullRequest): void
    {
        $this->getEntityManager()->remove($pullRequest);
        $this->getEntityManager()->flush();
    }

    public function saveComment(Comment $comment): void
    {
        $this->getEntityManager()->persist($comment);
        $this->getEntityManager()->flush();
    }

    /**
     * @return list<Comment>
     */
    public function commentsFor(PullRequest $pullRequest): array
    {
        /** @var list<Comment> $comments */
        $comments = $this->getEntityManager()
            ->getRepository(Comment::class)
            ->findBy(['pullRequest' => $pullRequest], ['createdAt' => 'ASC', 'id' => 'ASC']);

        return $comments;
    }

    public function commentById(int $id): ?Comment
    {
        return $this->getEntityManager()->getRepository(Comment::class)->find($id);
    }

    public function removeComment(Comment $comment): void
    {
        $this->getEntityManager()->remove($comment);
        $this->getEntityManager()->flush();
    }
}

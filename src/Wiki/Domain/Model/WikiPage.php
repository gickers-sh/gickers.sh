<?php

declare(strict_types=1);

namespace App\Wiki\Domain\Model;

use App\Identity\Domain\Model\User;
use App\Wiki\Domain\WikiSlug;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'wiki_pages')]
#[ORM\UniqueConstraint(name: 'UNIQ_WP_SLUG', columns: ['slug'])]
#[ORM\Index(name: 'IDX_WP_CAT_POS', columns: ['category', 'position'])]
class WikiPage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 128, unique: true)]
    private string $slug;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        WikiSlug $slug,
        #[ORM\Column(length: 255)]
        private string $title,
        #[ORM\Column(type: 'text')]
        private string $content,
        #[ORM\Column(length: 128)]
        private string $category,
        #[ORM\Column]
        private int $position,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'author_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $author,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->slug = $slug->value;
        $this->updatedAt = $createdAt;
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('WikiPage is not persisted yet.');
    }

    public function slug(): WikiSlug
    {
        return new WikiSlug($this->slug);
    }

    public function slugString(): string
    {
        return $this->slug;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function author(): ?User
    {
        return $this->author;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function update(
        string $title,
        string $content,
        string $category,
        int $position,
        ?User $author,
        \DateTimeImmutable $now,
    ): void {
        $this->title = $title;
        $this->content = $content;
        $this->category = $category;
        $this->position = $position;
        $this->author = $author;
        $this->updatedAt = $now;
    }
}

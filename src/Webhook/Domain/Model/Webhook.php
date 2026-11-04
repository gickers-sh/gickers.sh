<?php

declare(strict_types=1);

namespace App\Webhook\Domain\Model;

use App\Repo\Domain\Model\Repo;
use App\Webhook\Domain\ContentType;
use App\Webhook\Domain\WebhookEvent;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'webhooks')]
#[ORM\UniqueConstraint(columns: ['repo_id', 'url'])]
class Webhook
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $events;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param list<WebhookEvent> $events empty means every event
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Repo::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Repo $repo,
        #[ORM\Column(length: 2048)]
        private string $url,
        #[ORM\Column(length: 255)]
        private string $secret,
        #[ORM\Column(length: 8, enumType: ContentType::class)]
        private ContentType $contentType,
        #[ORM\Column]
        private bool $isActive,
        array $events,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->events = array_values(array_unique(array_map(static fn (WebhookEvent $e): string => $e->value, $events)));
        $this->updatedAt = $createdAt;
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('Webhook is not persisted yet.');
    }

    public function repo(): Repo
    {
        return $this->repo;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function secret(): string
    {
        return $this->secret;
    }

    public function contentType(): ContentType
    {
        return $this->contentType;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /** @return list<WebhookEvent> */
    public function events(): array
    {
        return array_map(static fn (string $e): WebhookEvent => WebhookEvent::from($e), $this->events);
    }

    public function subscribesTo(WebhookEvent $event): bool
    {
        return [] === $this->events || \in_array($event->value, $this->events, true);
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

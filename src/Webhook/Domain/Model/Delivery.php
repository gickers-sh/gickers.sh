<?php

declare(strict_types=1);

namespace App\Webhook\Domain\Model;

use App\Webhook\Domain\WebhookEvent;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'webhook_deliveries')]
#[ORM\Index(columns: ['webhook_id', 'created_at'])]
class Delivery
{
    /**
     * @param array<string, string>      $requestHeaders
     * @param array<string, string>|null $responseHeaders
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\ManyToOne(targetEntity: Webhook::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Webhook $webhook,
        #[ORM\Column(length: 64, enumType: WebhookEvent::class)]
        private WebhookEvent $event,
        #[ORM\Column(length: 2048)]
        private string $requestUrl,
        #[ORM\Column(length: 8)]
        private string $requestMethod,
        #[ORM\Column(type: 'json')]
        private array $requestHeaders,
        #[ORM\Column(type: 'text')]
        private string $requestBody,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $requestError,
        #[ORM\Column(nullable: true)]
        private ?int $responseStatus,
        #[ORM\Column(type: 'json', nullable: true)]
        private ?array $responseHeaders,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $responseBody,
        #[ORM\Column(nullable: true)]
        private ?int $durationMs,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function webhook(): Webhook
    {
        return $this->webhook;
    }

    public function event(): WebhookEvent
    {
        return $this->event;
    }

    public function requestUrl(): string
    {
        return $this->requestUrl;
    }

    public function requestMethod(): string
    {
        return $this->requestMethod;
    }

    /** @return array<string, string> */
    public function requestHeaders(): array
    {
        return $this->requestHeaders;
    }

    public function requestBody(): string
    {
        return $this->requestBody;
    }

    public function requestError(): ?string
    {
        return $this->requestError;
    }

    public function responseStatus(): ?int
    {
        return $this->responseStatus;
    }

    /** @return array<string, string>|null */
    public function responseHeaders(): ?array
    {
        return $this->responseHeaders;
    }

    public function responseBody(): ?string
    {
        return $this->responseBody;
    }

    public function durationMs(): ?int
    {
        return $this->durationMs;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

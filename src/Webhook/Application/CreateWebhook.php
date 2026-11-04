<?php

declare(strict_types=1);

namespace App\Webhook\Application;

use App\Access\Application\AccessGuard;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Domain\Exception\NotFound;
use App\Shared\Infrastructure\Security\SsrfGuard;
use App\Webhook\Domain\ContentType;
use App\Webhook\Domain\Model\Webhook;
use App\Webhook\Domain\WebhookEvent;
use App\Webhook\Domain\WebhookStore;
use Psr\Clock\ClockInterface;

/**
 * Creates a new webhook for a repository. Requires repository admin permissions.
 */
final readonly class CreateWebhook
{
    public function __construct(
        private AccessGuard $accessGuard,
        private WebhookStore $webhookStore,
        private SsrfGuard $ssrfGuard,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<WebhookEvent|string> $events
     */
    public function __invoke(
        ?User $actor,
        RepoName|string $repoName,
        string $url,
        ContentType|string $contentType = ContentType::Json,
        string $secret = '',
        array $events = [],
        bool $isActive = true,
    ): Webhook {
        $repoName = $repoName instanceof RepoName ? $repoName : RepoName::fromInput($repoName);
        $repo = $this->accessGuard->assertRepo($actor, $repoName, AccessLevel::AdminAccess);
        if (null === $repo) {
            throw new NotFound('repository not found');
        }

        $url = trim($url);
        $this->ssrfGuard->assertPublicUrl($url);

        $existing = $this->webhookStore->findByUrl($repo, $url);
        if (null !== $existing) {
            throw new Conflict('webhook already exists');
        }

        $ct = match (true) {
            $contentType instanceof ContentType => $contentType,
            default => ContentType::tryFrom(strtolower(trim($contentType)))
                ?? throw new InvalidInput(sprintf('invalid content type "%s"', $contentType)),
        };

        $parsedEvents = [];
        foreach ($events as $event) {
            if ($event instanceof WebhookEvent) {
                $parsedEvents[] = $event;
            } else {
                $ev = WebhookEvent::tryFrom(trim($event));
                if (null === $ev) {
                    throw new InvalidInput(sprintf('invalid event "%s"', $event));
                }
                $parsedEvents[] = $ev;
            }
        }

        $webhook = new Webhook(
            repo: $repo,
            url: $url,
            secret: $secret,
            contentType: $ct,
            isActive: $isActive,
            events: $parsedEvents,
            createdAt: $this->clock->now(),
        );

        $this->webhookStore->save($webhook);

        return $webhook;
    }
}

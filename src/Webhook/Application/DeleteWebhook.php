<?php

declare(strict_types=1);

namespace App\Webhook\Application;

use App\Access\Application\AccessGuard;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\Exception\NotFound;
use App\Webhook\Domain\WebhookStore;

/**
 * Deletes a webhook from a repository. Requires repository admin permissions.
 */
final readonly class DeleteWebhook
{
    public function __construct(
        private AccessGuard $accessGuard,
        private WebhookStore $webhookStore,
    ) {
    }

    public function __invoke(?User $actor, RepoName|string $repoName, int $id): void
    {
        $repoName = $repoName instanceof RepoName ? $repoName : RepoName::fromInput($repoName);
        $repo = $this->accessGuard->assertRepo($actor, $repoName, AccessLevel::AdminAccess);
        if (null === $repo) {
            throw new NotFound('repository not found');
        }

        $webhook = $this->webhookStore->byId($id);
        if (null === $webhook || $webhook->repo()->id() !== $repo->id()) {
            throw new NotFound('webhook not found');
        }

        $this->webhookStore->remove($webhook);
    }
}

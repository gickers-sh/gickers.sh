<?php

declare(strict_types=1);

namespace App\Webhook\Application\Query;

use App\Access\Application\AccessGuard;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\Exception\NotFound;
use App\Webhook\Domain\Model\Webhook;
use App\Webhook\Domain\WebhookStore;

final readonly class WebhookQuery
{
    public function __construct(
        private AccessGuard $accessGuard,
        private WebhookStore $webhookStore,
    ) {}

    /**
     * @return list<Webhook>
     */
    public function list(?User $actor, RepoName|string $repoName): array
    {
        $repoName = $repoName instanceof RepoName ? $repoName : RepoName::fromInput($repoName);
        $repo = $this->accessGuard->assertRepo($actor, $repoName, AccessLevel::AdminAccess);
        if (null === $repo) {
            throw new NotFound('repository not found');
        }

        return $this->webhookStore->forRepo($repo);
    }

    public function get(?User $actor, RepoName|string $repoName, int $id): Webhook
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

        return $webhook;
    }
}

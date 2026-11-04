<?php

declare(strict_types=1);

namespace App\Webhook\Domain;

use App\Repo\Domain\Model\Repo;
use App\Webhook\Domain\Model\Delivery;
use App\Webhook\Domain\Model\Webhook;

/**
 * Persistence port for webhooks and their delivery log.
 */
interface WebhookStore
{
    public function byId(int $id): ?Webhook;

    /** @return list<Webhook> ordered by id */
    public function forRepo(Repo $repo): array;

    /** @return list<Webhook> ordered by id (by raw repository id, usable after the Repo entity is detached) */
    public function forRepoId(int $repoId): array;

    public function findByUrl(Repo $repo, string $url): ?Webhook;

    public function save(Webhook $webhook): void;

    public function remove(Webhook $webhook): void;

    public function saveDelivery(Delivery $delivery): void;
}

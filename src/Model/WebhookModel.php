<?php

declare(strict_types=1);

namespace App\Model;

/**
 * WebhookModel represents a repository webhook subscription.
 */
final class WebhookModel
{
    /**
     * @param list<string|int> $events
     */
    public function __construct(
        public readonly ?int $id,
        public readonly int $repoId,
        public readonly string $url,
        public readonly string $secret,
        public readonly string $contentType = 'json',
        public readonly bool $active = true,
        public readonly array $events = [],
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?\DateTimeImmutable $updatedAt = null,
    ) {
    }

    public function hasEvent(string|int $event): bool
    {
        if (empty($this->events)) {
            return true;
        }

        if (in_array($event, $this->events, false)) {
            return true;
        }

        // Event mapping: 1 => branch_tag_create, 2 => branch_tag_delete, 3 => collaborator, 4 => push, 5 => repository, 6 => repository_visibility_change
        $map = [
            1 => 'branch_tag_create',
            2 => 'branch_tag_delete',
            3 => 'collaborator',
            4 => 'push',
            5 => 'repository',
            6 => 'repository_visibility_change',
        ];

        if (is_int($event) && isset($map[$event])) {
            if (in_array($map[$event], $this->events, true)) {
                return true;
            }
            if ((1 === $event || 2 === $event) && in_array('branch_tag', $this->events, true)) {
                return true;
            }
        } elseif (is_string($event)) {
            $flipped = array_flip($map);
            if (isset($flipped[$event]) && in_array($flipped[$event], $this->events, true)) {
                return true;
            }
            if ('branch_tag' === $event && (in_array(1, $this->events, true) || in_array(2, $this->events, true))) {
                return true;
            }
        }

        $eventInt = is_numeric($event) ? (int) $event : crc32((string) $event);

        return in_array($eventInt, $this->events, true);
    }
}

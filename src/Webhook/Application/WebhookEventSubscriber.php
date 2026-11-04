<?php

declare(strict_types=1);

namespace App\Webhook\Application;

use App\Identity\Domain\UserStore;
use App\Repo\Domain\CollaboratorStore;
use App\Repo\Domain\Event\BranchTagCreated;
use App\Repo\Domain\Event\BranchTagDeleted;
use App\Repo\Domain\Event\CollaboratorAdded;
use App\Repo\Domain\Event\CollaboratorRemoved;
use App\Repo\Domain\Event\PushReceived;
use App\Repo\Domain\Event\RepoDeleted;
use App\Repo\Domain\Event\RepoRenamed;
use App\Repo\Domain\Event\RepoVisibilityChanged;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoStore;
use App\Webhook\Application\Message\SendWebhook;
use App\Webhook\Domain\WebhookEvent;
use App\Webhook\Domain\WebhookStore;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Subscribes to repository domain events and dispatches asynchronous SendWebhook commands.
 */
final readonly class WebhookEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RepoStore $repoStore,
        private WebhookStore $webhookStore,
        private UserStore $userStore,
        private CollaboratorStore $collaboratorStore,
        private PayloadFactory $payloadFactory,
        private MessageBusInterface $messageBus,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            BranchTagCreated::class => 'onBranchTagCreated',
            BranchTagDeleted::class => 'onBranchTagDeleted',
            PushReceived::class => 'onPushReceived',
            CollaboratorAdded::class => 'onCollaboratorAdded',
            CollaboratorRemoved::class => 'onCollaboratorRemoved',
            RepoRenamed::class => 'onRepoRenamed',
            RepoVisibilityChanged::class => 'onRepoVisibilityChanged',
            RepoDeleted::class => 'onRepoDeleted',
        ];
    }

    public function onBranchTagCreated(BranchTagCreated $event): void
    {
        $repo = $this->repoStore->byId($event->repoId);
        if (null === $repo) {
            return;
        }

        $sender = null !== $event->actorId ? $this->userStore->byId($event->actorId) : null;
        $payload = $this->payloadFactory->forBranchTag(
            $repo,
            $sender,
            $event->ref,
            '0000000000000000000000000000000000000000',
            $event->newSha,
        );

        $this->dispatchForSubscribers($repo, WebhookEvent::BranchTagCreate, $payload);
    }

    public function onBranchTagDeleted(BranchTagDeleted $event): void
    {
        $repo = $this->repoStore->byId($event->repoId);
        if (null === $repo) {
            return;
        }

        $sender = null !== $event->actorId ? $this->userStore->byId($event->actorId) : null;
        $payload = $this->payloadFactory->forBranchTag(
            $repo,
            $sender,
            $event->ref,
            $event->oldSha,
            '0000000000000000000000000000000000000000',
        );

        $this->dispatchForSubscribers($repo, WebhookEvent::BranchTagDelete, $payload);
    }

    public function onPushReceived(PushReceived $event): void
    {
        $repo = $this->repoStore->byId($event->repoId);
        if (null === $repo || [] === $event->changes) {
            return;
        }

        $primary = $event->changes[0];
        $sender = null !== $event->actorId ? $this->userStore->byId($event->actorId) : null;
        $payload = $this->payloadFactory->forPush(
            $repo,
            $sender,
            $primary['ref'],
            $primary['old'],
            $primary['new'],
        );

        $this->dispatchForSubscribers($repo, WebhookEvent::Push, $payload);
    }

    public function onCollaboratorAdded(CollaboratorAdded $event): void
    {
        $repo = $this->repoStore->byId($event->repoId);
        $collabUser = $this->userStore->byId($event->userId);
        if (null === $repo || null === $collabUser) {
            return;
        }

        $sender = null !== $event->actorId ? $this->userStore->byId($event->actorId) : null;
        $collab = $this->collaboratorStore->find($repo, $collabUser);
        $accessLevel = $collab?->accessLevel()->value ?? 0;

        $payload = $this->payloadFactory->forCollaborator($repo, $sender, $collabUser, 'added', $accessLevel);
        $this->dispatchForSubscribers($repo, WebhookEvent::Collaborator, $payload);
    }

    public function onCollaboratorRemoved(CollaboratorRemoved $event): void
    {
        $repo = $this->repoStore->byId($event->repoId);
        $collabUser = $this->userStore->byId($event->userId);
        if (null === $repo || null === $collabUser) {
            return;
        }

        $sender = null !== $event->actorId ? $this->userStore->byId($event->actorId) : null;
        $payload = $this->payloadFactory->forCollaborator($repo, $sender, $collabUser, 'removed', 0);
        $this->dispatchForSubscribers($repo, WebhookEvent::Collaborator, $payload);
    }

    public function onRepoRenamed(RepoRenamed $event): void
    {
        $repo = $this->repoStore->byId($event->repoId);
        if (null === $repo) {
            return;
        }

        $sender = null !== $event->actorId ? $this->userStore->byId($event->actorId) : null;
        $payload = $this->payloadFactory->forRepository($repo, $sender, 'rename');
        $this->dispatchForSubscribers($repo, WebhookEvent::Repository, $payload);
    }

    public function onRepoVisibilityChanged(RepoVisibilityChanged $event): void
    {
        $repo = $this->repoStore->byId($event->repoId);
        if (null === $repo) {
            return;
        }

        $sender = null !== $event->actorId ? $this->userStore->byId($event->actorId) : null;
        $payload = $this->payloadFactory->forRepository($repo, $sender, 'visibility_change');
        $this->dispatchForSubscribers($repo, WebhookEvent::RepositoryVisibilityChange, $payload);
    }

    /**
     * Parity note: Go has webhooks.repo_id ON DELETE CASCADE and sends SendEvent after repo delete;
     * querying by repo_id returns no webhooks so deletion webhooks are a no-op.
     */
    public function onRepoDeleted(RepoDeleted $event): void
    {
        $repo = $this->repoStore->byId($event->repoId);
        if (null === $repo) {
            return;
        }

        $sender = null !== $event->actorId ? $this->userStore->byId($event->actorId) : null;
        $payload = $this->payloadFactory->forRepository($repo, $sender, 'delete');
        $this->dispatchForSubscribers($repo, WebhookEvent::Repository, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dispatchForSubscribers(Repo $repo, WebhookEvent $event, array $payload): void
    {
        $webhooks = $this->webhookStore->forRepoId($repo->id());
        foreach ($webhooks as $webhook) {
            if ($webhook->isActive() && $webhook->subscribesTo($event)) {
                $this->messageBus->dispatch(new SendWebhook($webhook->id(), $event->value, $payload));
            }
        }
    }
}

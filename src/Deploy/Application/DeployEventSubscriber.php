<?php

declare(strict_types=1);

namespace App\Deploy\Application;

use App\Deploy\Application\Message\DestroyEnvironment;
use App\Deploy\Domain\DeployStore;
use App\Deploy\Domain\Model\DeployEnvironment;
use App\Identity\Domain\UserStore;
use App\Repo\Domain\Event\BranchTagDeleted;
use App\Repo\Domain\Event\PushReceived;
use App\Repo\Domain\RepoStore;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class DeployEventSubscriber implements EventSubscriberInterface
{
    private const ZERO_SHA = '0000000000000000000000000000000000000000';

    public function __construct(
        private RepoStore $repoStore,
        private DeployStore $deployStore,
        private DeployAccessResolver $accessResolver,
        private UserStore $userStore,
        private TriggerDeploy $triggerDeploy,
        private MessageBusInterface $messageBus,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PushReceived::class => 'onPushReceived',
            BranchTagDeleted::class => 'onBranchTagDeleted',
        ];
    }

    public function onPushReceived(PushReceived $event): void
    {
        $repo = $this->repoStore->byId($event->repoId);
        if (null === $repo || [] === $event->changes) {
            return;
        }

        $actor = null !== $event->actorId ? $this->userStore->byId($event->actorId) : null;
        $actor ??= $repo->owner();

        foreach ($event->changes as $change) {
            if (self::ZERO_SHA === $change['new']) {
                continue;
            }

            $refName = DeployEnvironment::normalizeRefName($change['ref']);
            $environment = $this->deployStore->environmentByRef($repo, $refName);
            if (null === $environment || !$environment->isEnabled()) {
                continue;
            }

            if (!$this->accessResolver->isRepoRefDeployAllowed($repo, $refName, $actor)) {
                continue;
            }

            $this->triggerDeploy->queueForEnvironment($environment, $change['new'], $actor, coalesceIfActive: true, triggerSource: 'git push');
        }
    }

    public function onBranchTagDeleted(BranchTagDeleted $event): void
    {
        $repo = $this->repoStore->byId($event->repoId);
        if (null === $repo) {
            return;
        }

        $refName = DeployEnvironment::normalizeRefName($event->ref);
        $environment = $this->deployStore->environmentByRef($repo, $refName);
        if (null === $environment) {
            return;
        }

        $this->messageBus->dispatch(new DestroyEnvironment($environment->id()));
    }
}

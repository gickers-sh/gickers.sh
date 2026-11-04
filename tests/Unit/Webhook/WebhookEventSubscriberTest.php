<?php

declare(strict_types=1);

namespace App\Tests\Unit\Webhook;

use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Domain\CollaboratorStore;
use App\Repo\Domain\Event\BranchTagCreated;
use App\Repo\Domain\Event\CollaboratorAdded;
use App\Repo\Domain\Event\RepoVisibilityChanged;
use App\Repo\Domain\Model\Collaborator;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoDirectory;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\Webhook\Application\Message\SendWebhook;
use App\Webhook\Application\PayloadFactory;
use App\Webhook\Application\WebhookEventSubscriber;
use App\Webhook\Domain\ContentType;
use App\Webhook\Domain\Model\Webhook;
use App\Webhook\Domain\WebhookEvent;
use App\Webhook\Domain\WebhookStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class WebhookEventSubscriberTest extends TestCase
{
    private RepoStore&MockObject $repoStore;

    private WebhookStore&MockObject $webhookStore;

    private UserStore&MockObject $userStore;

    private CollaboratorStore&MockObject $collabStore;

    private PayloadFactory $payloadFactory;
    private MessageBusInterface $messageBus;
    private WebhookEventSubscriber $subscriber;

    /** @var list<SendWebhook> */
    private array $dispatchedMessages = [];

    protected function setUp(): void
    {
        $this->repoStore = $this->createMock(RepoStore::class);
        $this->webhookStore = $this->createMock(WebhookStore::class);
        $this->userStore = $this->createMock(UserStore::class);
        $this->collabStore = $this->createMock(CollaboratorStore::class);

        $settings = new ServerSettings('test', '/tmp', ':2222', 'ssh://localhost', ':8080', 'http://localhost', ':9418', 32, true, 'read-only', false, '');
        $gitReader = new GitRepositoryReader();
        $repoDir = $this->createMock(RepoDirectory::class);
        $this->payloadFactory = new PayloadFactory($settings, $gitReader, $repoDir);

        $this->dispatchedMessages = [];
        $this->messageBus = new class($this->dispatchedMessages) implements MessageBusInterface {
            /** @param list<SendWebhook> $messages */
            public function __construct(private array &$messages)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                if ($message instanceof SendWebhook) {
                    $this->messages[] = $message;
                }

                return new Envelope($message);
            }

            /** @return list<SendWebhook> */
            public function getMessages(): array
            {
                return $this->messages;
            }
        };

        $this->subscriber = new WebhookEventSubscriber(
            repoStore: $this->repoStore,
            webhookStore: $this->webhookStore,
            userStore: $this->userStore,
            collaboratorStore: $this->collabStore,
            payloadFactory: $this->payloadFactory,
            messageBus: $this->messageBus,
        );
    }

    public function testBranchTagCreatedDispatchesSendWebhook(): void
    {
        $owner = new User('owner', true, new \DateTimeImmutable());
        $rUser = new \ReflectionProperty(User::class, 'id');
        $rUser->setValue($owner, 1);

        $repo = new Repo(RepoName::fromInput('test-repo'), $owner, 'proj', 'desc', false, false, false, null, new \DateTimeImmutable());
        $rRepo = new \ReflectionProperty(Repo::class, 'id');
        $rRepo->setValue($repo, 1);

        $this->repoStore->method('byId')->willReturn($repo);

        $webhook = new Webhook($repo, 'https://example.com/hook', '', ContentType::Json, true, [WebhookEvent::BranchTagCreate], new \DateTimeImmutable());
        $r = new \ReflectionProperty(Webhook::class, 'id');
        $r->setValue($webhook, 42);

        $this->webhookStore->method('forRepoId')->willReturn([$webhook]);

        $event = new BranchTagCreated(1, 'refs/tags/v1.0', 'sha123', null);
        $this->subscriber->onBranchTagCreated($event);

        $this->assertCount(1, $this->dispatchedMessages);
        $this->assertSame(42, $this->dispatchedMessages[0]->webhookId);
        $this->assertSame('branch_tag_create', $this->dispatchedMessages[0]->event);
    }

    public function testCollaboratorEventsDispatchSendWebhook(): void
    {
        $owner = new User('owner', true, new \DateTimeImmutable());
        $rOwner = new \ReflectionProperty(User::class, 'id');
        $rOwner->setValue($owner, 1);

        $collabUser = new User('collab', false, new \DateTimeImmutable());
        $rUser = new \ReflectionProperty(User::class, 'id');
        $rUser->setValue($collabUser, 99);

        $repo = new Repo(RepoName::fromInput('test-repo'), $owner, 'proj', 'desc', false, false, false, null, new \DateTimeImmutable());
        $rRepo = new \ReflectionProperty(Repo::class, 'id');
        $rRepo->setValue($repo, 1);

        $this->repoStore->method('byId')->willReturn($repo);
        $this->userStore->method('byId')->willReturn($collabUser);

        $webhook = new Webhook($repo, 'https://example.com/hook', '', ContentType::Json, true, [WebhookEvent::Collaborator], new \DateTimeImmutable());
        $rHook = new \ReflectionProperty(Webhook::class, 'id');
        $rHook->setValue($webhook, 7);
        $this->webhookStore->method('forRepoId')->willReturn([$webhook]);

        $collab = new Collaborator($repo, $collabUser, AccessLevel::ReadWriteAccess, new \DateTimeImmutable());
        $this->collabStore->method('find')->willReturn($collab);

        $this->subscriber->onCollaboratorAdded(new CollaboratorAdded(1, 99, null));
        $this->assertCount(1, $this->dispatchedMessages);
        $this->assertSame('collaborator', $this->dispatchedMessages[0]->event);
    }

    public function testRepoVisibilityChangedDispatchesSendWebhook(): void
    {
        $owner = new User('owner', true, new \DateTimeImmutable());
        $rOwner = new \ReflectionProperty(User::class, 'id');
        $rOwner->setValue($owner, 1);

        $repo = new Repo(RepoName::fromInput('test-repo'), $owner, 'proj', 'desc', false, false, false, null, new \DateTimeImmutable());
        $rRepo = new \ReflectionProperty(Repo::class, 'id');
        $rRepo->setValue($repo, 1);

        $this->repoStore->method('byId')->willReturn($repo);

        $webhook = new Webhook($repo, 'https://example.com/hook', '', ContentType::Json, true, [WebhookEvent::RepositoryVisibilityChange], new \DateTimeImmutable());
        $rHook = new \ReflectionProperty(Webhook::class, 'id');
        $rHook->setValue($webhook, 12);
        $this->webhookStore->method('forRepoId')->willReturn([$webhook]);

        $this->subscriber->onRepoVisibilityChanged(new RepoVisibilityChanged(1, true, null));
        $this->assertCount(1, $this->dispatchedMessages);
        $this->assertSame(12, $this->dispatchedMessages[0]->webhookId);
    }
}

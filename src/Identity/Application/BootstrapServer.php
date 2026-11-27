<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Model\SshKey;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\Wiki\Application\DefaultWikiPages;
use App\Wiki\Domain\Model\WikiPage;
use App\Wiki\Domain\WikiSlug;
use App\Wiki\Domain\WikiStore;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class BootstrapServer
{
    public function __construct(
        private UserStore $userStore,
        private ServerSettings $serverSettings,
        private ClockInterface $clock,
        private ?WikiStore $wikiStore = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(): void
    {
        $now = $this->clock->now();
        $admin = $this->userStore->byUsername('admin');

        if (null === $admin) {
            $admin = new User('admin', true, $now);
            $this->userStore->save($admin);
        } elseif (!$admin->isAdmin()) {
            $admin->setAdmin(true, $now);
            $this->userStore->save($admin);
        }

        foreach ($this->serverSettings->initialAdminKeys as $keyLine) {
            $sshKey = SshKey::parse($keyLine);
            $owner = $this->userStore->byFingerprint($sshKey->fingerprint);

            if (null === $owner) {
                $admin->addPublicKey($sshKey, $now);
                $this->userStore->save($admin);
            } elseif ($owner->id() !== $admin->id()) {
                $this->logger?->warning('Initial admin SSH key is already registered to another user; refusing to promote non-admin account.', [
                    'fingerprint' => $sshKey->fingerprint,
                    'existing_user' => $owner->username(),
                ]);
            }
        }

        if (null !== $this->wikiStore) {
            foreach (DefaultWikiPages::all() as $seed) {
                $slug = new WikiSlug($seed['slug']);
                if (null === $this->wikiStore->bySlug($slug)) {
                    $this->wikiStore->save(new WikiPage(
                        slug: $slug,
                        title: $seed['title'],
                        content: $seed['content'],
                        category: $seed['category'],
                        position: $seed['position'],
                        author: $admin,
                        createdAt: $now,
                    ));
                }
            }
        }
    }
}

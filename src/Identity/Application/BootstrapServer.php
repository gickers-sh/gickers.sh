<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Event\PublicKeysChanged;
use App\Identity\Domain\Model\SshKey;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Shared\Infrastructure\Config\ServerSettings;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class BootstrapServer
{
    public function __construct(
        private UserStore $userStore,
        private ServerSettings $serverSettings,
        private ClockInterface $clock,
        private EventDispatcherInterface $eventDispatcher,
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

        $keysChanged = false;
        foreach ($this->serverSettings->initialAdminKeys as $keyLine) {
            $sshKey = SshKey::parse($keyLine);
            $owner = $this->userStore->byFingerprint($sshKey->fingerprint);

            if (null === $owner) {
                $admin->addPublicKey($sshKey, $now);
                $this->userStore->save($admin);
                $keysChanged = true;
            } elseif (!$owner->isAdmin()) {
                $owner->setAdmin(true, $now);
                $this->userStore->save($owner);
            }
        }

        if ($keysChanged) {
            $this->eventDispatcher->dispatch(new PublicKeysChanged($admin->id()));
        }
    }
}

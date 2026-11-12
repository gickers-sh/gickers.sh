<?php

declare(strict_types=1);

namespace App\Access\Application;

use App\Access\Domain\AccessLevel;
use App\Access\Domain\ServerSetting;
use App\Access\Domain\SettingKey;
use App\Shared\Infrastructure\Config\ServerSettings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class ServerPolicy
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ServerSettings $serverSettings,
        private ClockInterface $clock,
    ) {}

    public function anonAccess(): AccessLevel
    {
        $setting = $this->entityManager->find(ServerSetting::class, SettingKey::AnonAccess);
        if (null !== $setting) {
            return AccessLevel::fromString($setting->value());
        }

        return AccessLevel::tryFromString($this->serverSettings->anonAccess) ?? AccessLevel::NoAccess;
    }

    public function setAnonAccess(AccessLevel $level): void
    {
        $now = $this->clock->now();
        $setting = $this->entityManager->find(ServerSetting::class, SettingKey::AnonAccess);
        if (null !== $setting) {
            $setting->change($level->toString(), $now);
        } else {
            $setting = new ServerSetting(SettingKey::AnonAccess, $level->toString(), $now);
            $this->entityManager->persist($setting);
        }
        $this->entityManager->flush();
    }

    public function allowKeyless(): bool
    {
        $setting = $this->entityManager->find(ServerSetting::class, SettingKey::AllowKeyless);
        if (null !== $setting) {
            return filter_var($setting->value(), \FILTER_VALIDATE_BOOLEAN);
        }

        return $this->serverSettings->allowKeyless;
    }

    public function setAllowKeyless(bool $allow): void
    {
        $now = $this->clock->now();
        $value = $allow ? 'true' : 'false';
        $setting = $this->entityManager->find(ServerSetting::class, SettingKey::AllowKeyless);
        if (null !== $setting) {
            $setting->change($value, $now);
        } else {
            $setting = new ServerSetting(SettingKey::AllowKeyless, $value, $now);
            $this->entityManager->persist($setting);
        }
        $this->entityManager->flush();
    }

    public function allowSignup(): bool
    {
        $setting = $this->entityManager->find(ServerSetting::class, SettingKey::AllowSignup);
        if (null !== $setting) {
            return filter_var($setting->value(), \FILTER_VALIDATE_BOOLEAN);
        }

        // Self-service signup is on by default; admins disable it via `settings allow-signup false`.
        return true;
    }

    public function setAllowSignup(bool $allow): void
    {
        $now = $this->clock->now();
        $value = $allow ? 'true' : 'false';
        $setting = $this->entityManager->find(ServerSetting::class, SettingKey::AllowSignup);
        if (null !== $setting) {
            $setting->change($value, $now);
        } else {
            $setting = new ServerSetting(SettingKey::AllowSignup, $value, $now);
            $this->entityManager->persist($setting);
        }
        $this->entityManager->flush();
    }
}

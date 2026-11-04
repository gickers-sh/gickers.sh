<?php

declare(strict_types=1);

namespace App\Access\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Runtime-editable server policy value (anon access level, keyless access).
 */
#[ORM\Entity]
#[ORM\Table(name: 'server_settings')]
class ServerSetting
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 64, enumType: SettingKey::class)]
        private SettingKey $name,
        #[ORM\Column(type: 'text')]
        private string $value,
        #[ORM\Column]
        private \DateTimeImmutable $updatedAt,
    ) {
    }

    public function name(): SettingKey
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function change(string $value, \DateTimeImmutable $now): void
    {
        $this->value = $value;
        $this->updatedAt = $now;
    }
}

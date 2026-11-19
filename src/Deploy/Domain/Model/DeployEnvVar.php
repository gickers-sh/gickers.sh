<?php

declare(strict_types=1);

namespace App\Deploy\Domain\Model;

use App\Shared\Domain\Exception\InvalidInput;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'deploy_env_vars')]
#[ORM\UniqueConstraint(columns: ['environment_id', 'name'])]
class DeployEnvVar
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 128)]
    private string $name;

    #[ORM\Column(type: 'text')]
    private string $encryptedValue;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: DeployEnvironment::class, inversedBy: 'envVars')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private DeployEnvironment $environment,
        string $name,
        string $encryptedValue,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->name = self::normalizeName($name);
        $this->encryptedValue = $encryptedValue;
        $this->updatedAt = $createdAt;
        $environment->addEnvVar($this);
    }

    public static function normalizeName(string $name): string
    {
        $name = trim($name);
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/', $name)) {
            throw new InvalidInput(sprintf('invalid environment variable name "%s"', $name));
        }

        return $name;
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('DeployEnvVar is not persisted yet.');
    }

    public function environment(): DeployEnvironment
    {
        return $this->environment;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function encryptedValue(): string
    {
        return $this->encryptedValue;
    }

    public function updateEncryptedValue(string $encryptedValue, \DateTimeImmutable $now): void
    {
        $this->encryptedValue = $encryptedValue;
        $this->updatedAt = $now;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

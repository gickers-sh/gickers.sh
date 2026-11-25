<?php

declare(strict_types=1);

namespace App\Identity\Domain\Model;

use Doctrine\ORM\Mapping as ORM;

/**
 * Personal access token; only the sha256 of the secret is stored.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_access_tokens')]
class AccessToken
{
    public const PREFIX = 'ss_';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    /** @var list<string>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $scopes = null;

    /** @var list<string>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $repos = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $singleUse = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    /**
     * @param list<string>|null $scopes
     * @param list<string>|null $repos
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 255)]
        private string $name,
        #[ORM\Column(length: 64, unique: true)]
        private string $tokenHash,
        #[ORM\Column(nullable: true)]
        private ?\DateTimeImmutable $expiresAt,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
        ?array $scopes = null,
        ?array $repos = null,
        ?string $description = null,
        bool $singleUse = false,
    ) {
        $this->scopes = $scopes;
        $this->repos = $repos;
        $this->description = $description;
        $this->singleUse = $singleUse;
    }

    /**
     * @param list<string> $scopes
     * @param list<string> $repos
     * @return array{AccessToken, string} the entity and the one-time plaintext secret
     */
    public static function issue(
        User $user,
        string $name,
        ?\DateTimeImmutable $expiresAt,
        \DateTimeImmutable $now,
        array $scopes = ['*'],
        array $repos = [],
        ?string $description = null,
        bool $singleUse = false,
    ): array {
        $secret = self::PREFIX . bin2hex(random_bytes(20));

        return [
            new self($user, $name, self::hash($secret), $expiresAt, $now, $scopes, $repos, $description, $singleUse),
            $secret,
        ];
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('Token is not persisted yet.');
    }

    public function user(): User
    {
        return $this->user;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function expiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function lastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    /** @return list<string> */
    public function scopes(): array
    {
        return $this->scopes ?? ['*'];
    }

    /** @return list<string> */
    public function repos(): array
    {
        return $this->repos ?? [];
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function isSingleUse(): bool
    {
        return $this->singleUse;
    }

    public function isUsed(): bool
    {
        return null !== $this->usedAt;
    }

    public function usedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return null !== $this->expiresAt && $this->expiresAt <= $now;
    }

    public function markUsed(\DateTimeImmutable $now): void
    {
        $this->lastUsedAt = $now;
        if ($this->singleUse) {
            $this->usedAt = $now;
        }
    }

    public function hasScope(string $scope): bool
    {
        $scopes = $this->scopes();
        if (in_array('*', $scopes, true) || in_array('all', $scopes, true)) {
            return true;
        }

        if (in_array($scope, $scopes, true)) {
            return true;
        }

        // Hierarchy check, e.g. 'write' covers 'read', 'repo:write' covers 'repo:read', 'admin' covers everything
        if (in_array('admin', $scopes, true) || in_array('repo:admin', $scopes, true)) {
            return true;
        }

        if (str_ends_with($scope, ':read') || 'read' === $scope) {
            return in_array('write', $scopes, true) || in_array('repo:write', $scopes, true);
        }

        return false;
    }

    public function allowsRepo(string $repo): bool
    {
        $repos = $this->repos();
        if ([] === $repos) {
            return true;
        }

        $sanitized = preg_replace('/\.git$/', '', $repo) ?? $repo;
        foreach ($repos as $r) {
            $rSanitized = preg_replace('/\.git$/', '', $r) ?? $r;
            if (0 === strcasecmp($sanitized, $rSanitized)) {
                return true;
            }
        }

        return false;
    }
}

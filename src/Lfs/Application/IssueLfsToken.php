<?php

declare(strict_types=1);

namespace App\Lfs\Application;

use App\Access\Application\AccessGuard;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\Lfs\Domain\TokenIssuer;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Infrastructure\Config\ServerSettings;
use Psr\Clock\ClockInterface;

/**
 * Issues a short-lived Git LFS access token for one repository/operation,
 * returning the batch-action `header`, the LFS `href`, `expires_in` (seconds) and `expires_at` (RFC 3339),
 * i.e. the git-lfs `git-lfs-authenticate` response shape.
 *
 * Deviation from Go `pkg/ssh/cmd/jwt.go` (which signs `sub=username#id` for a list
 * of repositories): this programmatic contract signs `sub=userId` for a single
 * repository after an access check. The HTTP bearer handler accepts both subject
 * shapes, so tokens from here and from the SSH `jwt` command are interchangeable.
 */
final readonly class IssueLfsToken
{
    private const TTL_SECONDS = 3600;

    public function __construct(
        private AccessGuard $accessGuard,
        private TokenIssuer $issuer,
        private ServerSettings $settings,
        private ClockInterface $clock,
    ) {}

    /**
     * @param 'upload'|'download'|string $operation
     *
     * @return array{header: array<string, string>, href: string, expires_in: int, expires_at: string}
     */
    public function __invoke(?User $actor, RepoName|string $repo, string $operation): array
    {
        $name = $repo instanceof RepoName ? $repo : RepoName::fromInput($repo);

        $need = match ($operation) {
            'upload' => AccessLevel::ReadWriteAccess,
            'download' => AccessLevel::ReadOnlyAccess,
            default => throw new InvalidInput(sprintf('unknown LFS operation "%s"', $operation)),
        };

        // A token must carry an identity (Go `jwt` requires a signed-in user).
        if (null === $actor) {
            throw new AccessDenied('unauthorized');
        }
        $this->accessGuard->assertRepo($actor, $name, $need);

        $now = $this->clock->now();
        $expiresAt = $now->add(new \DateInterval('PT' . self::TTL_SECONDS . 'S'));
        $issuer = $this->settings->httpPublicUrl;

        $token = $this->issuer->issue((string) $actor->id(), [$name->value], $issuer, $now, $expiresAt);

        return [
            'header' => ['Authorization' => 'Bearer ' . $token],
            'href' => sprintf('%s/%s.git/info/lfs', rtrim($issuer, '/'), $name->value),
            'expires_in' => self::TTL_SECONDS,
            'expires_at' => $expiresAt->format(\DateTimeInterface::RFC3339),
        ];
    }
}

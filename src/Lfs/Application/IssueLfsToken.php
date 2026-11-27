<?php

declare(strict_types=1);

namespace App\Lfs\Application;

use App\Access\Application\AccessGuard;
use App\Access\Domain\AccessLevel;
use App\Identity\Application\IssueAccessToken;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Infrastructure\Config\ServerSettings;
use Psr\Clock\ClockInterface;

/**
 * Issues a short-lived Git LFS access token for one repository/operation,
 * returning the batch-action `header`, the LFS `href`, `expires_in` (seconds) and `expires_at` (RFC 3339),
 * i.e. the git-lfs `git-lfs-authenticate` response shape.
 */
final readonly class IssueLfsToken
{
    private const TTL_SECONDS = 3600;

    public function __construct(
        private AccessGuard $accessGuard,
        private IssueAccessToken $issueAccessToken,
        private ServerSettings $settings,
        private ClockInterface $clock,
    ) {
    }

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

        if (null === $actor) {
            throw new AccessDenied('unauthorized');
        }
        $this->accessGuard->assertRepo($actor, $name, $need);

        $now = $this->clock->now();
        $expiresAt = $now->add(new \DateInterval('PT'.self::TTL_SECONDS.'S'));
        $issuer = $this->settings->httpPublicUrl;

        $ephemeralScope = 'upload' === $operation ? 'lfs:upload' : 'lfs:download';

        [, $secret] = ($this->issueAccessToken)(
            actor: $actor,
            name: sprintf('lfs-%s-%s', $name->value, bin2hex(random_bytes(3))),
            expiresAt: $expiresAt,
            scopes: [$ephemeralScope],
            repos: [$name->value],
            description: sprintf('Git LFS ephemeral %s token for %s', $operation, $name->value),
        );

        return [
            'header' => ['Authorization' => 'Bearer '.$secret],
            'href' => sprintf('%s/%s.git/info/lfs', rtrim($issuer, '/'), $name->value),
            'expires_in' => self::TTL_SECONDS,
            'expires_at' => $expiresAt->format(\DateTimeInterface::RFC3339),
        ];
    }
}

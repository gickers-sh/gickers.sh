<?php

declare(strict_types=1);

namespace App\Deploy\Domain\Model;

use App\Deploy\Domain\ReleaseStatus;
use App\Identity\Domain\Model\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'deploy_releases')]
class DeployRelease
{
    private const MAX_BUILD_LOG_BYTES = 524_288;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: ReleaseStatus::class)]
    private ReleaseStatus $status = ReleaseStatus::Queued;

    #[ORM\Column(type: 'text')]
    private string $buildLog = '';

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: DeployEnvironment::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private DeployEnvironment $environment,
        #[ORM\Column(length: 40)]
        private string $commitSha,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?User $triggeredBy,
        #[ORM\Column]
        private \DateTimeImmutable $startedAt,
    ) {
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('DeployRelease is not persisted yet.');
    }

    public function environment(): DeployEnvironment
    {
        return $this->environment;
    }

    public function commitSha(): string
    {
        return $this->commitSha;
    }

    public function updateQueuedTarget(string $commitSha, ?User $triggeredBy, \DateTimeImmutable $now): void
    {
        $this->commitSha = $commitSha;
        $this->triggeredBy = $triggeredBy;
        $this->startedAt = $now;
    }

    public function triggeredBy(): ?User
    {
        return $this->triggeredBy;
    }

    public function triggerDisplay(): string
    {
        $actor = $this->triggeredBy ?? $this->environment->repo()->owner();
        $username = '@'.$actor->username();

        if (str_contains($this->buildLog, '(git push)') || str_contains($this->buildLog, 'by git push') || null === $this->triggeredBy) {
            return sprintf('%s (git push)', $username);
        }

        if (str_contains($this->buildLog, '(manual)') || str_contains($this->buildLog, 'manually')) {
            return sprintf('%s (manual)', $username);
        }

        if (str_contains($this->buildLog, '(reconcile)') || str_contains($this->buildLog, 'reconcile')) {
            return sprintf('%s (reconcile)', $username);
        }

        return $username;
    }

    public function status(): ReleaseStatus
    {
        return $this->status;
    }

    public function buildLog(): string
    {
        return $this->buildLog;
    }

    public function startedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function finishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function markBuilding(): void
    {
        $this->status = ReleaseStatus::Building;
    }

    public function appendLog(string $chunk): void
    {
        $this->buildLog .= $chunk;
        if (\strlen($this->buildLog) > self::MAX_BUILD_LOG_BYTES) {
            $this->buildLog = "... [build log truncated]\n".substr($this->buildLog, -self::MAX_BUILD_LOG_BYTES);
        }
    }

    public function markSucceeded(\DateTimeImmutable $finishedAt): void
    {
        $this->status = ReleaseStatus::Succeeded;
        $this->finishedAt = $finishedAt;
    }

    public function markFailed(string $reason, \DateTimeImmutable $finishedAt): void
    {
        if ('' !== $reason) {
            $this->buildLog .= ('' !== $this->buildLog && !str_ends_with($this->buildLog, "\n") ? "\n" : '').'[error] '.$reason."\n";
        }
        $this->status = ReleaseStatus::Failed;
        $this->finishedAt = $finishedAt;
    }
}

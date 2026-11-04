<?php

declare(strict_types=1);

namespace App\Repo\Application;

/**
 * A single reference update inside a push (one git receive-pack ref change).
 *
 * Shas are lowercase hex; a zero sha (empty or all-zero) marks the absence of the
 * ref on that side: `oldSha` zero means the ref was created, `newSha` zero means it
 * was deleted. Carried verbatim by {@see \App\Repo\Domain\Event\PushReceived}.
 */
final readonly class PushChange
{
    public function __construct(
        public string $ref,
        public string $oldSha,
        public string $newSha,
    ) {
    }

    public function isCreate(): bool
    {
        return $this->isZero($this->oldSha) && !$this->isZero($this->newSha);
    }

    public function isDelete(): bool
    {
        return !$this->isZero($this->oldSha) && $this->isZero($this->newSha);
    }

    private function isZero(string $sha): bool
    {
        return '' === $sha || '' === trim($sha, '0');
    }
}

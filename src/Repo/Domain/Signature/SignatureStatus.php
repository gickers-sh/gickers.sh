<?php

declare(strict_types=1);

namespace App\Repo\Domain\Signature;

/**
 * Verification state of a commit signature. Enforcement only accepts {@see Verified};
 * the UI distinguishes {@see Unsigned} from a present-but-untrusted signature.
 */
enum SignatureStatus
{
    /** No signature on the commit (git `%G?` == `N`). */
    case Unsigned;

    /** A good signature made by a key linked to an account (git `%G?` == `G`). */
    case Verified;

    /** A signature that is bad, made by an unknown key, or otherwise uncheckable. */
    case Unverified;

    public function isVerified(): bool
    {
        return self::Verified === $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::Unsigned => 'unsigned',
            self::Verified => 'verified',
            self::Unverified => 'unverified',
        };
    }

    /**
     * Maps a git `%G?` status character to a {@see SignatureStatus}.
     * G/U/B/E/X/Y/R/N per `git-show` PRETTY FORMATS.
     */
    public static function fromGitStatus(string $code): self
    {
        return match (strtoupper(trim($code))) {
            'G' => self::Verified,
            'N', '' => self::Unsigned,
            default => self::Unverified,
        };
    }
}

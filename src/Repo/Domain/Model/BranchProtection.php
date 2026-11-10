<?php

declare(strict_types=1);

namespace App\Repo\Domain\Model;

use Doctrine\ORM\Mapping as ORM;

/**
 * A branch protection rule attached to a {@see Repo}. A rule matches push ref
 * updates by glob pattern and carries the explicit policy flags evaluated by the
 * pre-receive hook: `no-force`, `no-delete`, `admins-only`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'repo_branch_protections')]
#[ORM\UniqueConstraint(columns: ['repo_id', 'pattern'])]
class BranchProtection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Repo::class, inversedBy: 'branchProtections')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Repo $repo,
        #[ORM\Column(length: 255)]
        private string $pattern,
        #[ORM\Column]
        private bool $noForce,
        #[ORM\Column]
        private bool $noDelete,
        #[ORM\Column]
        private bool $adminsOnly,
    ) {
    }

    public function repo(): Repo
    {
        return $this->repo;
    }

    public function pattern(): string
    {
        return $this->pattern;
    }

    public function noForce(): bool
    {
        return $this->noForce;
    }

    public function noDelete(): bool
    {
        return $this->noDelete;
    }

    public function adminsOnly(): bool
    {
        return $this->adminsOnly;
    }

    public function update(bool $noForce, bool $noDelete, bool $adminsOnly): void
    {
        $this->noForce = $noForce;
        $this->noDelete = $noDelete;
        $this->adminsOnly = $adminsOnly;
    }

    /**
     * Whether this rule matches a full ref name (e.g. `refs/heads/main`,
     * `refs/tags/v1`). The pattern is tested against the full ref and against the
     * short branch/tag name, so `main` or `release/*` cover `refs/heads/...`.
     */
    public function matches(string $ref): bool
    {
        if (fnmatch($this->pattern, $ref)) {
            return true;
        }

        foreach (['refs/heads/', 'refs/tags/'] as $prefix) {
            if (str_starts_with($ref, $prefix)) {
                return fnmatch($this->pattern, substr($ref, \strlen($prefix)));
            }
        }

        return false;
    }
}

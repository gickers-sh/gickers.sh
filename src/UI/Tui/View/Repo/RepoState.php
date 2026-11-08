<?php

declare(strict_types=1);

namespace App\UI\Tui\View\Repo;

use App\Identity\Domain\Model\User;
use App\Repo\Domain\Git\GitRepository;
use App\Repo\Domain\Model\Repo;

/**
 * Shared, mutable state for one open repository view in the TUI.
 *
 * Holds the current reference, subpath and metadata. Decoupled from asynchronous
 * loading (managed by {@see RepoData}), so views can observe and mutate navigation
 * state without process-management concerns.
 */
final class RepoState
{
    public string $name;
    public string $ref;
    public string $subpath = '';

    /** @var list<\Closure(string): void> */
    private array $refChangeListeners = [];

    /** @var list<string>|null */
    private ?array $branches = null;

    /** @var (\Closure(): list<string>)|null */
    private ?\Closure $branchesProvider = null;

    /** @var (\Closure(string): string)|null */
    private ?\Closure $shaProvider = null;

    public function __construct(
        public readonly GitRepository $git,
        public readonly ?Repo $db,
        public readonly ?User $actor,
        public readonly string $sshPublicUrl = '',
    ) {
        $this->name = $this->db?->nameString() ?? $this->git->name;
        $this->ref = $this->git->defaultBranch;
    }

    /**
     * Whether the current ref is a branch (not a tag or detached sha);
     * commits can only target a branch.
     */
    public function currentRefIsBranch(): bool
    {
        if (null !== $this->branches) {
            return \in_array($this->ref, $this->branches, true);
        }
        $gitDir = $this->git->isBare ? $this->git->path : $this->git->path.'/.git';
        if (is_file($gitDir.'/refs/heads/'.$this->ref)) {
            return true;
        }
        if (null !== $this->branchesProvider) {
            return \in_array($this->ref, ($this->branchesProvider)(), true);
        }

        return false;
    }

    /**
     * Sets cached branch names to avoid filesystem or git calls.
     *
     * @param list<string>|null $branches
     */
    public function setBranches(?array $branches): void
    {
        $this->branches = $branches;
    }

    /**
     * Provider closure called if branches are not cached and the loose ref file is absent.
     *
     * @param (\Closure(): list<string>)|null $provider
     */
    public function setBranchesProvider(?\Closure $provider): void
    {
        $this->branchesProvider = $provider;
    }

    /**
     * The `git clone` command advertised in the repo header and profile, or an
     * empty string when no public SSH URL is configured.
     */
    public function cloneCommand(): string
    {
        if ('' === $this->sshPublicUrl) {
            return '';
        }

        return 'git clone '.rtrim($this->sshPublicUrl, '/').'/'.$this->name.'.git';
    }

    /**
     * Switches the active git ref, resets subpath and notifies listeners.
     */
    public function switchRef(string $ref): void
    {
        if ('' === $ref || $ref === $this->ref) {
            return;
        }
        $this->ref = $ref;
        $this->subpath = '';
        foreach ($this->refChangeListeners as $listener) {
            $listener($ref);
        }
    }

    /**
     * @param \Closure(string): void $listener
     */
    public function onRefChange(\Closure $listener): void
    {
        $this->refChangeListeners[] = $listener;
    }

    /**
     * Resolved commit sha for the current ref.
     */
    public function sha(): string
    {
        if (null !== $this->shaProvider) {
            return ($this->shaProvider)($this->ref);
        }

        return $this->resolveLooseSha($this->ref);
    }

    /**
     * @param (\Closure(string): string)|null $provider
     */
    public function setShaProvider(?\Closure $provider): void
    {
        $this->shaProvider = $provider;
    }

    private function resolveLooseSha(string $ref): string
    {
        $gitDir = $this->git->isBare ? $this->git->path : $this->git->path.'/.git';
        $refFile = $gitDir.'/refs/heads/'.$ref;
        if (is_file($refFile)) {
            $sha = trim((string) file_get_contents($refFile));
            if (40 === \strlen($sha) && ctype_xdigit($sha)) {
                return $sha;
            }
        }

        return '';
    }
}

<?php

declare(strict_types=1);

namespace App\UI\Tui\Page\Repo;

use App\CodeIntel\Application\Query\CodeIntelQuery;
use App\Identity\Domain\Model\User;
use App\PullRequest\Application\MergePullRequest;
use App\PullRequest\Application\Query\PullRequestQuery;
use App\PullRequest\Domain\Model\PullRequest;
use App\PullRequest\Application\ClosePullRequest;
use App\PullRequest\Application\CommentPullRequest;
use App\PullRequest\Application\DeletePullRequest;
use App\PullRequest\Application\OpenPullRequest;
use App\PullRequest\Application\ReopenPullRequest;
use App\PullRequest\Application\UpdatePullRequest;
use App\Repo\Application\UpdateRepoSettings;
use App\Repo\Domain\Git\Commit;
use App\Repo\Domain\Git\GitRepository;
use App\Repo\Domain\Git\TreeEntry;
use App\Repo\Application\DeleteFile;
use App\Repo\Application\WriteFile;
use App\Repo\Domain\Model\Repo;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\UI\Tui\Render\TermstrapRenderer;

/**
 * Shared, mutable state and memoised data accessors for one open repository.
 *
 * Every {@see RepoTab} reads services and cached git data through this context,
 * so the tab classes stay focused on their own keyboard handling and painting.
 * The current {@see $ref}, {@see $flash} message and {@see $subpath} are shared
 * because they cut across tabs (a branch switch from the Branches tab resets the
 * Files tab's directory and the README/tree/commit caches).
 */
final class RepoContext
{
    public string $name;
    public string $ref;
    public ?string $flash = null;

    /** Files tab directory; reset on ref switch. */
    public string $subpath = '';

    /** @var array<string,string> ref => sha */
    private array $shaCache = [];
    /** @var array{key: string, lines: list<string>}|null */
    private ?array $readmeCache = null;
    /** @var array<string, list<TreeEntry>> */
    private array $treeCache = [];
    /** @var list<Commit>|null */
    private ?array $commits = null;
    /** @var array{branches: list<string>, tags: list<string>, current: string}|null */
    private ?array $refsData = null;
    /** @var list<PullRequest>|null */
    public ?array $pullsCache = null;

    public function __construct(
        public readonly GitRepository $git,
        public readonly ?Repo $db,
        public readonly ?User $actor,
        public readonly GitRepositoryReader $reader,
        public readonly TermstrapRenderer $renderer,
        public readonly CodeIntelQuery $codeIntel,
        public readonly UpdateRepoSettings $updateSettings,
        public readonly ?PullRequestQuery $pullRequestQuery = null,
        public readonly ?MergePullRequest $mergePullRequest = null,
        public readonly ?WriteFile $writeFile = null,
        public readonly ?DeleteFile $deleteFile = null,
        public readonly ?OpenPullRequest $openPullRequest = null,
        public readonly ?ClosePullRequest $closePullRequest = null,
        public readonly ?ReopenPullRequest $reopenPullRequest = null,
        public readonly ?DeletePullRequest $deletePullRequest = null,
        public readonly ?CommentPullRequest $commentPullRequest = null,
        public readonly ?UpdatePullRequest $updatePullRequest = null,
        public readonly string $sshPublicUrl = '',
    ) {
        $this->name = $this->db?->nameString() ?? $this->git->name;
        $this->ref = $this->git->defaultBranch;
    }

    /**
     * Whether the acting user may commit to this repository through the TUI:
     * the repo is registered ({@see $db}), a user is authenticated and the write
     * use cases are wired. Access level is enforced by the use cases themselves,
     * which surface {@see \App\Shared\Domain\Exception\AccessDenied} as a flash.
     */
    public function canWrite(): bool
    {
        return null !== $this->db && null !== $this->actor && null !== $this->writeFile;
    }

    /**
     * Whether the current {@see $ref} is a branch (not a tag or detached sha);
     * commits can only target a branch.
     */
    public function currentRefIsBranch(): bool
    {
        return \in_array($this->ref, $this->branchList(), true);
    }

    public function switchRef(string $ref): void
    {
        if ('' === $ref || $ref === $this->ref) {
            return;
        }
        $this->ref = $ref;
        $this->readmeCache = null;
        $this->treeCache = [];
        $this->commits = null;
        $this->subpath = '';
        $this->flash = 'Switched to ' . $ref;
    }

    /**
     * Drops the memoised git data (tree, commits, readme, resolved shas) after a
     * write so the Files/Commits/Readme tabs reflect the new commit on the next
     * paint. The ref itself is unchanged, so {@see switchRef} would be a no-op.
     */
    public function invalidateGitCaches(): void
    {
        $this->shaCache = [];
        $this->readmeCache = null;
        $this->treeCache = [];
        $this->commits = null;
    }

    /**
     * The `git clone` command advertised in the repo header and profile, or an
     * empty string when no public SSH URL is configured (soft:browse).
     */
    public function cloneCommand(): string
    {
        if ('' === $this->sshPublicUrl) {
            return '';
        }

        return 'git clone ' . rtrim($this->sshPublicUrl, '/') . '/' . $this->name . '.git';
    }

    // --- data accessors (memoised) -----------------------------------------

    public function sha(): string
    {
        return $this->shaCache[$this->ref] ??= $this->resolveSha($this->ref);
    }

    private function resolveSha(string $ref): string
    {
        try {
            $commits = $this->reader->getCommits($this->git, $ref, 1, 0);
        } catch (\Throwable) {
            return '';
        }

        return $commits[0]->hash ?? '';
    }

    /**
     * @return list<TreeEntry>
     */
    public function tree(): array
    {
        $key = $this->ref . ':' . $this->subpath;
        if (!isset($this->treeCache[$key])) {
            try {
                $this->treeCache[$key] = array_values($this->reader->getTree($this->git, $this->ref, $this->subpath));
            } catch (\Throwable) {
                $this->treeCache[$key] = [];
            }
        }

        return $this->treeCache[$key];
    }

    /**
     * @return list<Commit>
     */
    public function commitList(): array
    {
        if (null === $this->commits) {
            try {
                $this->commits = array_values($this->reader->getCommits($this->git, $this->ref, 100, 0));
            } catch (\Throwable) {
                $this->commits = [];
            }
        }

        return $this->commits;
    }

    /**
     * @return array{branches: list<string>, tags: list<string>, current: string}
     */
    public function refsData(): array
    {
        if (null === $this->refsData) {
            try {
                $refs = $this->reader->getRefs($this->git);
                $this->refsData = ['branches' => array_values($refs->branches), 'tags' => array_values($refs->tags), 'current' => $refs->currentBranch];
            } catch (\Throwable) {
                $this->refsData = ['branches' => [], 'tags' => [], 'current' => $this->ref];
            }
        }

        return $this->refsData;
    }

    /**
     * @return list<string>
     */
    public function branchList(): array
    {
        return $this->refsData()['branches'];
    }

    /**
     * @return list<string>
     */
    public function tagList(): array
    {
        return $this->refsData()['tags'];
    }

    /**
     * @return list<string>
     */
    public function readmeLines(int $cols): array
    {
        $key = $this->ref . ':' . $cols;
        if (null === $this->readmeCache || $this->readmeCache['key'] !== $key) {
            $doc = $this->reader->readme($this->git, $this->ref);
            if (null === $doc) {
                $lines = [];
            } elseif ($this->renderer->isAvailable()) {
                $ansi = $this->renderer->renderDocument($doc['path'], $doc['content'], max(20, $cols - 2));
                $lines = preg_split('/\r\n|\r|\n/', rtrim($ansi, "\n")) ?: [];
            } else {
                $lines = preg_split('/\r\n|\r|\n/', rtrim($doc['content'], "\n")) ?: [];
            }
            $this->readmeCache = ['key' => $key, 'lines' => $lines];
        }

        return $this->readmeCache['lines'];
    }

    public function readmeLineCount(int $cols = 80): int
    {
        return \count($this->readmeLines($cols));
    }

    /**
     * @return list<PullRequest>
     */
    public function pullList(): array
    {
        if (null === $this->db || null === $this->pullRequestQuery) {
            return [];
        }

        if (null === $this->pullsCache) {
            try {
                $this->pullsCache = $this->pullRequestQuery->list($this->actor, $this->db->name());
            } catch (\Throwable) {
                $this->pullsCache = [];
            }
        }

        return $this->pullsCache;
    }
}

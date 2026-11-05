<?php

declare(strict_types=1);

namespace App\UI\Tui\Page\Repo;

use App\CodeIntel\Application\Query\CodeIntelQuery;
use App\Identity\Domain\Model\User;
use App\PullRequest\Application\MergePullRequest;
use App\PullRequest\Application\Query\PullRequestQuery;
use App\PullRequest\Domain\Model\PullRequest;
use App\Repo\Application\UpdateRepoSettings;
use App\Repo\Domain\Git\Commit;
use App\Repo\Domain\Git\GitRepository;
use App\Repo\Domain\Git\TreeEntry;
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
        public readonly string $sshPublicUrl = '',
    ) {
        $this->name = $this->db?->nameString() ?? $this->git->name;
        $this->ref = $this->git->defaultBranch;
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
        $this->flash = 'Switched to '.$ref;
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

        return 'git clone '.rtrim($this->sshPublicUrl, '/').'/'.$this->name.'.git';
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
        $key = $this->ref.':'.$this->subpath;
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

    public function readmeLineCount(): int
    {
        return \count($this->readmeLines(120));
    }

    /**
     * @return list<string>
     */
    public function readmeLines(int $cols): array
    {
        $key = $this->ref.':'.$cols;
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

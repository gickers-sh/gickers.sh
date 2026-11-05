<?php

declare(strict_types=1);

namespace App\UI\Tui\Page\Repo;

use App\CodeIntel\Application\Query\CodeIntelQuery;
use App\Identity\Domain\Model\User;
use App\PullRequest\Application\ClosePullRequest;
use App\PullRequest\Application\CommentPullRequest;
use App\PullRequest\Application\DeletePullRequest;
use App\PullRequest\Application\MergePullRequest;
use App\PullRequest\Application\OpenPullRequest;
use App\PullRequest\Application\Query\PullRequestQuery;
use App\PullRequest\Application\ReopenPullRequest;
use App\PullRequest\Application\UpdatePullRequest;
use App\PullRequest\Domain\Model\PullRequest;
use App\Repo\Application\DeleteFile;
use App\Repo\Application\UpdateRepoSettings;
use App\Repo\Application\WriteFile;
use App\Repo\Domain\Git\Commit;
use App\Repo\Domain\Git\GitRepository;
use App\Repo\Domain\Git\TreeEntry;
use App\Repo\Domain\Model\Repo;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\UI\Tui\Render\Spinner;
use App\UI\Tui\Render\TermstrapRenderer;
use Symfony\Component\Process\Process;

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
    public readonly Spinner $spinner;

    /** Files tab directory; reset on ref switch. */
    public string $subpath = '';

    private int $lastReadmeCols = 76;
    /** @var array<string,string> ref => sha */
    private array $shaCache = [];
    /** @var array{key: string, lines: list<string>}|null */
    private ?array $readmeCache = null;
    /** @var array<string, array{path: string, content: string}|null> ref => raw readme doc */
    private array $readmeDocCache = [];
    /** @var array{ref: string, stage: 'tree'|'blob', process: Process, path?: string}|null */
    private ?array $readmeJob = null;
    /** @var array{key: string, process: Process, fallback: list<string>}|null */
    private ?array $readmeRenderJob = null;
    /** @var array<string, list<TreeEntry>> */
    private array $treeCache = [];
    /** @var array{key: string, process: Process}|null */
    private ?array $treeJob = null;
    /** @var list<Commit>|null */
    private ?array $commits = null;
    /** @var array{ref: string, process: Process}|null */
    private ?array $commitsJob = null;
    /** @var array{branches: list<string>, tags: list<string>, current: string}|null */
    private ?array $refsData = null;
    /** @var array<string, array{hash: string, time: int}|null> */
    private array $refMetaCache = [];
    private ?Process $refsProcess = null;
    /** @var list<PullRequest>|null */
    public ?array $pullsCache = null;
    private bool $pullsPending = false;

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
        $this->spinner = new Spinner();
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
        $this->stopAllProcesses();
        $this->ref = $ref;
        $this->readmeCache = null;
        $this->treeCache = [];
        $this->commits = null;
        $this->subpath = '';
        $this->flash = 'Switched to '.$ref;
    }

    /**
     * Drops the memoised git data (tree, commits, readme, resolved shas) after a
     * write so the Files/Commits/Readme tabs reflect the new commit on the next
     * paint. The ref itself is unchanged, so {@see switchRef} would be a no-op.
     */
    public function invalidateGitCaches(): void
    {
        $this->stopAllProcesses();
        $this->shaCache = [];
        $this->readmeDocCache = [];
        $this->readmeCache = null;
        $this->treeCache = [];
        $this->commits = null;
        $this->refsData = null;
        $this->refMetaCache = [];
    }

    private function stopAllProcesses(): void
    {
        if (null !== $this->readmeJob && $this->readmeJob['process']->isRunning()) {
            $this->readmeJob['process']->stop(0);
        }
        $this->readmeJob = null;
        if (null !== $this->readmeRenderJob && $this->readmeRenderJob['process']->isRunning()) {
            $this->readmeRenderJob['process']->stop(0);
        }
        $this->readmeRenderJob = null;

        if (null !== $this->treeJob && $this->treeJob['process']->isRunning()) {
            $this->treeJob['process']->stop(0);
        }
        $this->treeJob = null;

        if (null !== $this->commitsJob && $this->commitsJob['process']->isRunning()) {
            $this->commitsJob['process']->stop(0);
        }
        $this->commitsJob = null;

        if (null !== $this->refsProcess && $this->refsProcess->isRunning()) {
            $this->refsProcess->stop(0);
        }
        $this->refsProcess = null;
    }

    /**
     * Advance the shared spinner and poll any in-flight git subprocesses.
     * Returns true while work is still pending.
     */
    public function tick(?float $deltaTime = null): bool
    {
        $busy = false;

        if (null !== $this->readmeJob) {
            $busy = true;
            $this->pollReadmeJob();
        }
        if (null !== $this->readmeRenderJob) {
            $busy = true;
            $this->pollReadmeRenderJob();
        }

        if (null !== $this->treeJob) {
            $busy = true;
            $this->pollTreeJob();
        }

        if (null !== $this->commitsJob) {
            $busy = true;
            $this->pollCommitsJob();
        }

        if (null !== $this->refsProcess) {
            $busy = true;
            $this->pollRefsProcess();
        }

        if ($this->pullsPending) {
            $busy = true;
            $this->pullsPending = false;
            if (null === $this->db || null === $this->pullRequestQuery) {
                $this->pullsCache = [];
            } else {
                try {
                    $this->pullsCache = $this->pullRequestQuery->list($this->actor, $this->db->name());
                } catch (\Throwable) {
                    $this->pullsCache = [];
                }
            }
        }

        if ($busy) {
            $this->spinner->advance($deltaTime);
        }

        return $busy;
    }

    public function isReadmeLoading(?int $cols = null): bool
    {
        if (\array_key_exists($this->ref, $this->readmeDocCache)) {
            $doc = $this->readmeDocCache[$this->ref];
            if (null === $doc) {
                return false;
            }
            if (null !== $cols) {
                $this->lastReadmeCols = $cols;
            } else {
                $cols = $this->lastReadmeCols;
            }
            $key = $this->ref.':'.$cols;
            if (null !== $this->readmeCache && $this->readmeCache['key'] === $key) {
                return false;
            }
            if (!$this->renderer->isAvailable()) {
                $lines = preg_split('/\r\n|\r|\n/', rtrim($doc['content'], "\n")) ?: [];
                $this->readmeCache = ['key' => $key, 'lines' => $lines];

                return false;
            }
            $this->ensureReadmeRenderJob($key, $doc, $cols);

            return true;
        }
        if (null === $this->readmeJob || $this->readmeJob['ref'] !== $this->ref) {
            if (null !== $this->readmeJob && $this->readmeJob['process']->isRunning()) {
                $this->readmeJob['process']->stop(0);
            }
            $rootKey = $this->ref.':';
            if (isset($this->treeCache[$rootKey])) {
                $entry = $this->reader->findReadmeEntry($this->treeCache[$rootKey]);
                if (null === $entry) {
                    $this->readmeDocCache[$this->ref] = null;

                    return false;
                }
                try {
                    $this->readmeJob = [
                        'ref' => $this->ref,
                        'stage' => 'blob',
                        'path' => $entry->path,
                        'process' => $this->reader->startBlobProcess($this->git, $this->ref, $entry->path),
                    ];
                } catch (\Throwable) {
                    $this->readmeDocCache[$this->ref] = null;

                    return false;
                }
            } else {
                try {
                    $this->readmeJob = [
                        'ref' => $this->ref,
                        'stage' => 'tree',
                        'process' => $this->reader->startTreeProcess($this->git, $this->ref, ''),
                    ];
                } catch (\Throwable) {
                    $this->readmeDocCache[$this->ref] = null;

                    return false;
                }
            }
        }

        return true;
    }

    private function pollReadmeJob(): void
    {
        if (null === $this->readmeJob || $this->readmeJob['process']->isRunning()) {
            return;
        }

        $job = $this->readmeJob;
        $this->readmeJob = null;
        $ref = $job['ref'];
        $proc = $job['process'];

        if ('tree' === $job['stage']) {
            if (!$proc->isSuccessful()) {
                $this->readmeDocCache[$ref] = null;

                return;
            }
            $entries = $this->reader->parseTreeOutput($proc->getOutput());
            $this->treeCache[$ref.':'] = $entries;
            $entry = $this->reader->findReadmeEntry($entries);
            if (null === $entry) {
                $this->readmeDocCache[$ref] = null;

                return;
            }
            try {
                $this->readmeJob = [
                    'ref' => $ref,
                    'stage' => 'blob',
                    'path' => $entry->path,
                    'process' => $this->reader->startBlobProcess($this->git, $ref, $entry->path),
                ];
            } catch (\Throwable) {
                $this->readmeDocCache[$ref] = null;
            }

            return;
        }

        // 'blob' stage finished
        if (!$proc->isSuccessful()) {
            $this->readmeDocCache[$ref] = null;

            return;
        }

        $doc = [
            'path' => $job['path'] ?? 'README.md',
            'content' => $proc->getOutput(),
        ];
        $this->readmeDocCache[$ref] = $doc;
        if ($ref === $this->ref) {
            $this->readmeCache = null;
            if ($this->renderer->isAvailable()) {
                $this->ensureReadmeRenderJob($ref.':'.$this->lastReadmeCols, $doc, $this->lastReadmeCols);
            } else {
                $lines = preg_split('/\r\n|\r|\n/', rtrim($doc['content'], "\n")) ?: [];
                $this->readmeCache = ['key' => $ref.':'.$this->lastReadmeCols, 'lines' => $lines];
            }
        }
    }

    private function pollReadmeRenderJob(): void
    {
        if (null === $this->readmeRenderJob || $this->readmeRenderJob['process']->isRunning()) {
            return;
        }

        $job = $this->readmeRenderJob;
        $this->readmeRenderJob = null;
        $proc = $job['process'];
        if ($proc->isSuccessful()) {
            $ansi = $proc->getOutput();
            $lines = preg_split('/\r\n|\r|\n/', rtrim($ansi, "\n")) ?: [];
        } else {
            $lines = $job['fallback'];
        }
        $this->readmeCache = ['key' => $job['key'], 'lines' => $lines];
    }

    /**
     * @param array{path: string, content: string} $doc
     */
    private function ensureReadmeRenderJob(string $key, array $doc, int $cols): void
    {
        if (null !== $this->readmeRenderJob && $this->readmeRenderJob['key'] === $key) {
            return;
        }
        if (null !== $this->readmeRenderJob && $this->readmeRenderJob['process']->isRunning()) {
            $this->readmeRenderJob['process']->stop(0);
        }
        $fallback = preg_split('/\r\n|\r|\n/', rtrim($doc['content'], "\n")) ?: [];
        try {
            $this->readmeRenderJob = [
                'key' => $key,
                'process' => $this->renderer->startRenderDocumentProcess($doc['path'], $doc['content'], max(20, $cols - 2)),
                'fallback' => $fallback,
            ];
        } catch (\Throwable) {
            $this->readmeCache = ['key' => $key, 'lines' => $fallback];
        }
    }

    public function isTreeLoading(): bool
    {
        $key = $this->ref.':'.$this->subpath;
        if (isset($this->treeCache[$key])) {
            return false;
        }
        if (null === $this->treeJob || $this->treeJob['key'] !== $key) {
            if (null !== $this->treeJob && $this->treeJob['process']->isRunning()) {
                $this->treeJob['process']->stop(0);
            }
            try {
                $this->treeJob = [
                    'key' => $key,
                    'process' => $this->reader->startTreeProcess($this->git, $this->ref, $this->subpath),
                ];
            } catch (\Throwable) {
                $this->treeCache[$key] = [];

                return false;
            }
        }

        return true;
    }

    private function pollTreeJob(): void
    {
        if (null === $this->treeJob || $this->treeJob['process']->isRunning()) {
            return;
        }
        $job = $this->treeJob;
        $this->treeJob = null;
        $proc = $job['process'];
        $this->treeCache[$job['key']] = $proc->isSuccessful()
            ? $this->reader->parseTreeOutput($proc->getOutput())
            : [];
    }

    public function isCommitsLoading(): bool
    {
        if (null !== $this->commits) {
            return false;
        }
        if (null === $this->commitsJob || $this->commitsJob['ref'] !== $this->ref) {
            if (null !== $this->commitsJob && $this->commitsJob['process']->isRunning()) {
                $this->commitsJob['process']->stop(0);
            }
            try {
                $this->commitsJob = [
                    'ref' => $this->ref,
                    'process' => $this->reader->startCommitsProcess($this->git, $this->ref, 100, 0),
                ];
            } catch (\Throwable) {
                $this->commits = [];

                return false;
            }
        }

        return true;
    }

    private function pollCommitsJob(): void
    {
        if (null === $this->commitsJob || $this->commitsJob['process']->isRunning()) {
            return;
        }
        $job = $this->commitsJob;
        $this->commitsJob = null;
        if ($job['ref'] !== $this->ref) {
            return;
        }
        $proc = $job['process'];
        $this->commits = $proc->isSuccessful()
            ? $this->reader->parseCommitsOutput($proc->getOutput())
            : [];
    }

    public function isRefsLoading(): bool
    {
        if (null !== $this->refsData) {
            return false;
        }
        if (null === $this->refsProcess) {
            try {
                $this->refsProcess = $this->reader->startRefsProcess($this->git);
            } catch (\Throwable) {
                $this->refsData = ['branches' => [], 'tags' => [], 'current' => $this->ref];

                return false;
            }
        }

        return true;
    }

    private function pollRefsProcess(): void
    {
        if (null === $this->refsProcess || $this->refsProcess->isRunning()) {
            return;
        }
        $proc = $this->refsProcess;
        $this->refsProcess = null;
        if ($proc->isSuccessful()) {
            $parsed = $this->reader->parseRefsProcessOutput($proc->getOutput());
            $this->refsData = [
                'branches' => $parsed['branches'],
                'tags' => $parsed['tags'],
                'current' => $this->git->defaultBranch,
            ];
            foreach ($parsed['meta'] as $ref => $meta) {
                $this->refMetaCache[$ref] = $meta;
            }
        } else {
            $this->refsData = ['branches' => [], 'tags' => [], 'current' => $this->ref];
        }
    }

    /**
     * @return array{hash: string, time: int}|null
     */
    public function refMeta(string $ref): ?array
    {
        if (!\array_key_exists($ref, $this->refMetaCache)) {
            try {
                $commits = $this->reader->getCommits($this->git, $ref, 1, 0);
            } catch (\Throwable) {
                $commits = [];
            }
            $this->refMetaCache[$ref] = [] !== $commits
                ? ['hash' => $commits[0]->hash, 'time' => $commits[0]->timestamp]
                : null;
        }

        return $this->refMetaCache[$ref];
    }

    public function isPullsLoading(): bool
    {
        if (null === $this->db || null === $this->pullRequestQuery) {
            return false;
        }
        if (null !== $this->pullsCache) {
            return false;
        }
        $this->pullsPending = true;

        return true;
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

    /**
     * @return list<string>
     */
    public function readmeLines(int $cols): array
    {
        $key = $this->ref.':'.$cols;
        if (null === $this->readmeCache || $this->readmeCache['key'] !== $key) {
            if (!\array_key_exists($this->ref, $this->readmeDocCache)) {
                $this->readmeDocCache[$this->ref] = $this->reader->readme($this->git, $this->ref);
            }
            $doc = $this->readmeDocCache[$this->ref];
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
        if (null !== $this->readmeCache) {
            return \count($this->readmeCache['lines']);
        }
        if ($this->isReadmeLoading($cols)) {
            return 0;
        }

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

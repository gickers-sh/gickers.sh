<?php

declare(strict_types=1);

namespace App\UI\Tui\View\Repo;

use App\Repo\Domain\Git\Commit;
use App\Repo\Domain\Git\TreeEntry;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\UI\Tui\AsyncLoader;
use Symfony\Component\Process\Process;

/**
 * Asynchronous data layer and memoised git accessors for repository views.
 */
final class RepoData
{
    /** @var array<string, string> ref => sha */
    private array $shaCache = [];
    /** @var array<string, list<TreeEntry>> 'ref:subpath' => entries */
    private array $treeCache = [];
    /** @var array<string, list<TreeEntry>> ref => entries */
    private array $recursiveTreeCache = [];
    /** @var array<string, list<Commit>> ref => commits */
    private array $commitsCache = [];
    /** @var array{branches: list<string>, tags: list<string>, current: string}|null */
    private ?array $refsData = null;
    /** @var array<string, array{hash: string, time: int}|null> */
    private array $refMetaCache = [];
    /** @var array<string, array{path: string, content: string}|null> ref => doc */
    private array $readmeDocCache = [];

    private ?int $diskUsage = null;
    private bool $diskUsageComputed = false;

    /** @var array<string, list<\Closure(list<TreeEntry>): void>> */
    private array $treeQueue = [];
    /** @var array<string, list<\Closure(list<TreeEntry>): void>> */
    private array $recursiveTreeQueue = [];
    /** @var array<string, list<\Closure(list<Commit>): void>> */
    private array $commitsQueue = [];
    /** @var list<\Closure(array{branches: list<string>, tags: list<string>, current: string}): void> */
    private array $refsQueue = [];
    /** @var array<string, list<\Closure(?array{path: string, content: string}): void>> */
    private array $readmeQueue = [];

    public function __construct(
        public readonly RepoState $state,
        private readonly GitRepositoryReader $reader,
        private readonly AsyncLoader $async,
    ) {
        $this->state->onRefChange(function (): void {
            $this->invalidate();
        });
        $this->state->setBranchesProvider(fn (): array => $this->branchList());
        $this->state->setShaProvider(fn (string $ref): string => $this->sha($ref));
    }

    /**
     * @param \Closure(list<TreeEntry>): void $onReady
     */
    public function tree(string $subpath, \Closure $onReady): void
    {
        $key = $this->state->ref.':'.$subpath;
        if (\array_key_exists($key, $this->treeCache)) {
            $onReady($this->treeCache[$key]);

            return;
        }
        if (isset($this->treeQueue[$key])) {
            $this->treeQueue[$key][] = $onReady;

            return;
        }

        $this->treeQueue[$key] = [$onReady];
        $ref = $this->state->ref;

        try {
            $process = $this->reader->startTreeProcess($this->state->git, $ref, $subpath);
        } catch (\Throwable) {
            $this->treeCache[$key] = [];
            $this->drainQueue($this->treeQueue, $key, []);

            return;
        }

        $this->async->watch($this, $process, function (Process $p) use ($key, $ref): void {
            if ($ref !== $this->state->ref) {
                unset($this->treeQueue[$key]);

                return;
            }
            $entries = $p->isSuccessful() ? $this->reader->parseTreeOutput($p->getOutput()) : [];
            $this->treeCache[$key] = $entries;
            $this->drainQueue($this->treeQueue, $key, $entries);
        });
    }

    /**
     * @param \Closure(list<TreeEntry>): void $onReady
     */
    public function recursiveTree(\Closure $onReady): void
    {
        $ref = $this->state->ref;
        if (\array_key_exists($ref, $this->recursiveTreeCache)) {
            $onReady($this->recursiveTreeCache[$ref]);

            return;
        }
        if (isset($this->recursiveTreeQueue[$ref])) {
            $this->recursiveTreeQueue[$ref][] = $onReady;

            return;
        }

        $this->recursiveTreeQueue[$ref] = [$onReady];

        try {
            $process = $this->reader->startRecursiveTreeProcess($this->state->git, $ref);
        } catch (\Throwable) {
            $this->recursiveTreeCache[$ref] = [];
            $this->drainQueue($this->recursiveTreeQueue, $ref, []);

            return;
        }

        $this->async->watch($this, $process, function (Process $p) use ($ref): void {
            if ($ref !== $this->state->ref) {
                unset($this->recursiveTreeQueue[$ref]);

                return;
            }
            $entries = $p->isSuccessful() ? $this->reader->parseTreeOutput($p->getOutput()) : [];
            $this->recursiveTreeCache[$ref] = $entries;
            $this->drainQueue($this->recursiveTreeQueue, $ref, $entries);
        });
    }

    /**
     * @param \Closure(list<Commit>): void $onReady
     */
    public function commits(\Closure $onReady): void
    {
        $ref = $this->state->ref;
        if (\array_key_exists($ref, $this->commitsCache)) {
            $onReady($this->commitsCache[$ref]);

            return;
        }
        if (isset($this->commitsQueue[$ref])) {
            $this->commitsQueue[$ref][] = $onReady;

            return;
        }

        $this->commitsQueue[$ref] = [$onReady];

        try {
            $process = $this->reader->startCommitsProcess($this->state->git, $ref, 100, 0);
        } catch (\Throwable) {
            $this->commitsCache[$ref] = [];
            $this->drainQueue($this->commitsQueue, $ref, []);

            return;
        }

        $this->async->watch($this, $process, function (Process $p) use ($ref): void {
            if ($ref !== $this->state->ref) {
                unset($this->commitsQueue[$ref]);

                return;
            }
            $commits = $p->isSuccessful() ? $this->reader->parseCommitsOutput($p->getOutput()) : [];
            $this->commitsCache[$ref] = $commits;
            $this->drainQueue($this->commitsQueue, $ref, $commits);
        });
    }

    /**
     * @param \Closure(array{branches: list<string>, tags: list<string>, current: string}): void $onReady
     */
    public function refs(\Closure $onReady): void
    {
        if (null !== $this->refsData) {
            $onReady($this->refsData);

            return;
        }
        if ([] !== $this->refsQueue) {
            $this->refsQueue[] = $onReady;

            return;
        }

        $this->refsQueue = [$onReady];

        try {
            $process = $this->reader->startRefsProcess($this->state->git);
        } catch (\Throwable) {
            $refsData = ['branches' => [], 'tags' => [], 'current' => $this->state->ref];
            $this->refsData = $refsData;
            $this->state->setBranches([]);
            $cbs = $this->refsQueue;
            $this->refsQueue = [];
            foreach ($cbs as $cb) {
                $cb($refsData);
            }

            return;
        }

        $this->async->watch($this, $process, function (Process $p): void {
            if ($p->isSuccessful()) {
                $parsed = $this->reader->parseRefsProcessOutput($p->getOutput());
                $refsData = [
                    'branches' => $parsed['branches'],
                    'tags' => $parsed['tags'],
                    'current' => $this->state->git->defaultBranch,
                ];
                foreach ($parsed['meta'] as $refName => $meta) {
                    $this->refMetaCache[$refName] = $meta;
                }
            } else {
                $refsData = ['branches' => [], 'tags' => [], 'current' => $this->state->ref];
            }

            $this->refsData = $refsData;
            $this->state->setBranches($refsData['branches']);
            $cbs = $this->refsQueue;
            $this->refsQueue = [];
            foreach ($cbs as $cb) {
                $cb($refsData);
            }
        });
    }

    /**
     * @param \Closure(?array{path: string, content: string}): void $onReady
     */
    public function readme(\Closure $onReady): void
    {
        $ref = $this->state->ref;
        if (\array_key_exists($ref, $this->readmeDocCache)) {
            $onReady($this->readmeDocCache[$ref]);

            return;
        }
        if (isset($this->readmeQueue[$ref])) {
            $this->readmeQueue[$ref][] = $onReady;

            return;
        }

        $this->readmeQueue[$ref] = [$onReady];

        $this->tree('', function (array $entries) use ($ref): void {
            if ($ref !== $this->state->ref) {
                unset($this->readmeQueue[$ref]);

                return;
            }
            $entry = $this->reader->findReadmeEntry($entries);
            if (null === $entry) {
                $this->finishReadme($ref, null);

                return;
            }
            $this->startReadmeBlob($ref, $entry->path);
        });
    }

    private function startReadmeBlob(string $ref, string $path): void
    {
        try {
            $process = $this->reader->startBlobProcess($this->state->git, $ref, $path);
        } catch (\Throwable) {
            $this->finishReadme($ref, null);

            return;
        }

        $this->async->watch($this, $process, function (Process $p) use ($ref, $path): void {
            if ($ref !== $this->state->ref) {
                unset($this->readmeQueue[$ref]);

                return;
            }
            if (!$p->isSuccessful()) {
                $this->finishReadme($ref, null);

                return;
            }
            $this->finishReadme($ref, ['path' => $path, 'content' => $p->getOutput()]);
        });
    }

    /**
     * @param array{path: string, content: string}|null $doc
     */
    private function finishReadme(string $ref, ?array $doc): void
    {
        $this->readmeDocCache[$ref] = $doc;
        $this->drainQueue($this->readmeQueue, $ref, $doc);
    }

    /**
     * @return array{hash: string, time: int}|null
     */
    public function refMeta(string $ref): ?array
    {
        if (!\array_key_exists($ref, $this->refMetaCache)) {
            try {
                $commits = $this->reader->getCommits($this->state->git, $ref, 1, 0);
            } catch (\Throwable) {
                $commits = [];
            }
            $this->refMetaCache[$ref] = [] !== $commits
                ? ['hash' => $commits[0]->hash, 'time' => $commits[0]->timestamp]
                : null;
        }

        return $this->refMetaCache[$ref];
    }

    /**
     * @return list<string>
     */
    public function branchList(): array
    {
        return $this->refsData['branches'] ?? [];
    }

    /**
     * @return list<string>
     */
    public function tagList(): array
    {
        return $this->refsData['tags'] ?? [];
    }

    public function isLoading(string $resource): bool
    {
        return match ($resource) {
            'tree' => [] !== $this->treeQueue,
            'recursiveTree' => [] !== $this->recursiveTreeQueue,
            'commits' => [] !== $this->commitsQueue,
            'refs' => [] !== $this->refsQueue,
            'readme' => [] !== $this->readmeQueue,
            default => false,
        };
    }

    public function invalidate(): void
    {
        $this->async->cancel($this);
        $this->shaCache = [];
        $this->readmeDocCache = [];
        $this->readmeQueue = [];
        $this->treeCache = [];
        $this->treeQueue = [];
        $this->recursiveTreeCache = [];
        $this->recursiveTreeQueue = [];
        $this->commitsCache = [];
        $this->commitsQueue = [];
        $this->refsData = null;
        $this->refsQueue = [];
        $this->refMetaCache = [];
        $this->state->setBranches(null);
    }

    public function sha(?string $ref = null): string
    {
        $targetRef = $ref ?? $this->state->ref;

        return $this->shaCache[$targetRef] ??= $this->resolveSha($targetRef);
    }

    private function resolveSha(string $ref): string
    {
        if (isset($this->refMetaCache[$ref]['hash']) && '' !== $this->refMetaCache[$ref]['hash']) {
            return $this->refMetaCache[$ref]['hash'];
        }
        $gitDir = $this->state->git->isBare ? $this->state->git->path : $this->state->git->path.'/.git';
        $refFile = $gitDir.'/refs/heads/'.$ref;
        if (is_file($refFile)) {
            $sha = trim((string) file_get_contents($refFile));
            if (40 === \strlen($sha) && ctype_xdigit($sha)) {
                return $sha;
            }
        }
        try {
            $commits = $this->reader->getCommits($this->state->git, $ref, 1, 0);
        } catch (\Throwable) {
            return '';
        }

        return $commits[0]->hash ?? '';
    }

    /**
     * On-disk size of the repository in bytes, computed once via `git count-objects`
     * and memoised. Null when the size cannot be determined.
     */
    public function diskUsageBytes(): ?int
    {
        if ($this->diskUsageComputed) {
            return $this->diskUsage;
        }
        $this->diskUsageComputed = true;
        try {
            $this->diskUsage = $this->reader->diskUsageBytes($this->state->git);
        } catch (\Throwable) {
            $this->diskUsage = null;
        }

        return $this->diskUsage;
    }

    /**
     * @template T
     *
     * @param array<string, list<\Closure(T): void>> $queue
     * @param T                                      $data
     */
    private function drainQueue(array &$queue, string $key, mixed $data): void
    {
        $cbs = $queue[$key] ?? [];
        unset($queue[$key]);
        foreach ($cbs as $cb) {
            $cb($data);
        }
    }
}

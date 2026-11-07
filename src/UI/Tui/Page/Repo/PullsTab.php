<?php

declare(strict_types=1);

namespace App\UI\Tui\Page\Repo;

use App\PullRequest\Domain\Model\Comment;
use App\PullRequest\Domain\Model\PullRequest;
use App\PullRequest\Domain\PullRequestStatus;
use App\Repo\Domain\Git\Commit;
use App\Repo\Domain\Git\Diff;
use App\Repo\Domain\Git\DiffFile;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Domain\Exception\NotFound;
use App\UI\Tui\Input\Keys;
use App\UI\Tui\Render\Editor;
use App\UI\Tui\Render\Screen;
use App\UI\Tui\Render\Theme;
use Symfony\Component\Process\Process;
use Symfony\Component\Tui\Input\KeyParser;

/**
 * Pull Requests tab: matches Go `pkg/ui/pages/repo/prs.go`, `pritem.go`,
 * `prform.go`, and `preditform.go`.
 *
 * Views:
 * - List view with two-line `PRItem` rows (`#N [STATUS] Title (base ← head)` / `opened <time>`)
 * - Detail view with 4 sub-tabs (`About | Commits | Changes | Comments`)
 * - Commit detail view inside PR (`prViewDetailCommit`)
 * - New PR modal form (`Head`, `Base`, `Title`, `Description` with branch dropdown & live Markdown preview)
 * - Edit PR modal form (`Title`, `Description`)
 * - Comment compose/edit view (`Add Comment` / `Edit Comment`)
 * - Delete PR / Delete Comment / Merge PR confirmation modals
 */
final class PullsTab extends AbstractRepoTab
{
    private const DETAIL_TABS = ['About', 'Commits', 'Changes', 'Comments'];
    private const TAB_ABOUT = 0;
    private const TAB_COMMITS = 1;
    private const TAB_CHANGES = 2;
    private const TAB_COMMENTS = 3;

    private bool $prDetailOpen = false;
    private ?int $prDetailNumber = null;
    private int $detailTab = self::TAB_ABOUT;

    // Detail data cached for active PR
    /** @var list<Commit> */
    private array $detailCommits = [];
    private ?Diff $detailDiff = null;
    /** @var list<Comment> */
    private array $detailComments = [];
    private bool $detailMergeable = false;

    private ?Process $detailCommitsProcess = null;
    private ?Process $detailDiffProcess = null;
    /** @var array{key: string, lines: list<string>}|null */
    private ?array $detailDescCache = null;
    /** @var array{key: string, process: Process, fallback: list<string>}|null */
    private ?array $detailDescJob = null;
    /** @var array<string, list<string>> */
    private array $commentRenderCache = [];
    /** @var array{key: string, process: Process, fallback: list<string>}|null */
    private ?array $commentRenderJob = null;
    // Detail sub-tab navigation state
    private int $selectedCommitIdx = 0;
    private int $selectedFileIdx = 0;
    /** @var array<int, bool> */
    private array $expandedFiles = [];
    /** @var list<int> */
    private array $commitLineOffsets = [];
    /** @var list<int> */
    private array $fileLineOffsets = [];
    private int $diffTotalLines = 0;

    // Commit diff view inside PR (prViewDetailCommit)
    private bool $prCommitDiffOpen = false;
    private ?Commit $viewingCommit = null;
    private ?Diff $viewingCommitDiff = null;
    private int $commitDiffScroll = 0;
    private ?Process $viewingCommitDiffProcess = null;

    // Standalone diff view (triggered by 'd')
    private bool $prDiffOpen = false;
    /** @var list<string> */
    private array $prDiffLines = [];
    private int $prDiffScroll = 0;
    private ?Process $prDiffProcess = null;

    // Modals & Confirmations
    private bool $prMergeConfirm = false;
    private bool $prDeleteConfirm = false;
    private bool $prDeleteCommentConfirm = false;
    private bool $commentMenuActive = false;
    private int $editingCommentId = 0;

    // Filter (null = all, matching Go default)
    private ?PullRequestStatus $filterStatus = null;

    // New PR Form
    private bool $prFormOpen = false;
    private int $prFormField = 0; // 0=head, 1=base, 2=title, 3=desc
    private string $prFormHead = '';
    private string $prFormBase = '';
    private string $prFormTitle = '';
    private ?Editor $prFormDescEditor = null;
    private bool $headGhostActive = false;
    private int $headGhostIndex = 0;
    private bool $baseGhostActive = false;
    private int $baseGhostIndex = 0;
    private ?string $prFormError = null;

    // Edit PR Form
    private bool $prEditOpen = false;
    private int $prEditField = 0; // 0=title, 1=desc
    private string $prEditTitle = '';
    private ?Editor $prEditDescEditor = null;
    private ?string $prEditError = null;

    // Comment Form
    private bool $prCommentOpen = false;
    private ?Editor $prCommentEditor = null;

    private ?KeyParser $keyParser = null;

    public function capturesInput(): bool
    {
        return $this->prDiffOpen
            || $this->prCommitDiffOpen
            || $this->prMergeConfirm
            || $this->prDeleteConfirm
            || $this->prDeleteCommentConfirm
            || $this->prFormOpen
            || $this->prEditOpen
            || $this->prCommentOpen
            || $this->prDetailOpen
            || $this->fuzzyOpen;
    }

    public function isFullScreenModal(): bool
    {
        return $this->prFormOpen || $this->prEditOpen;
    }

    public function customTabBarLines(int $cols): ?array
    {
        if ($this->prDetailOpen && !$this->prCommitDiffOpen && !$this->prDiffOpen && !$this->prCommentOpen && !$this->prDeleteConfirm && !$this->prDeleteCommentConfirm && !$this->prMergeConfirm) {
            return [Screen::tabBar(self::DETAIL_TABS, $this->detailTab, $cols), ''];
        }

        return null;
    }

    public function statusBranch(): ?string
    {
        if ($this->prDetailOpen || $this->prCommitDiffOpen) {
            $pr = $this->currentDetailPull();
            if (null !== $pr) {
                return sprintf('%s → %s', $pr->headRef(), $pr->baseRef());
            }
        }

        return null;
    }

    public function onActivate(): void
    {
        parent::onActivate();
        $this->stopAllProcesses();
        $this->prDetailOpen = false;
        $this->prDetailNumber = null;
        $this->prCommitDiffOpen = false;
        $this->viewingCommit = null;
        $this->viewingCommitDiff = null;
        $this->prMergeConfirm = false;
        $this->prDeleteConfirm = false;
        $this->prDeleteCommentConfirm = false;
        $this->commentMenuActive = false;
        $this->prDiffOpen = false;
        $this->prFormOpen = false;
        $this->prEditOpen = false;
        $this->prCommentOpen = false;
    }

    private function stopAllProcesses(): void
    {
        if (null !== $this->detailCommitsProcess && $this->detailCommitsProcess->isRunning()) {
            $this->detailCommitsProcess->stop(0);
        }
        $this->detailCommitsProcess = null;

        if (null !== $this->detailDiffProcess && $this->detailDiffProcess->isRunning()) {
            $this->detailDiffProcess->stop(0);
        }
        $this->detailDiffProcess = null;

        if (null !== $this->detailDescJob && $this->detailDescJob['process']->isRunning()) {
            $this->detailDescJob['process']->stop(0);
        }
        $this->detailDescJob = null;

        if (null !== $this->commentRenderJob && $this->commentRenderJob['process']->isRunning()) {
            $this->commentRenderJob['process']->stop(0);
        }
        $this->commentRenderJob = null;

        if (null !== $this->viewingCommitDiffProcess && $this->viewingCommitDiffProcess->isRunning()) {
            $this->viewingCommitDiffProcess->stop(0);
        }
        $this->viewingCommitDiffProcess = null;

        if (null !== $this->prDiffProcess && $this->prDiffProcess->isRunning()) {
            $this->prDiffProcess->stop(0);
        }
        $this->prDiffProcess = null;

        $this->prFormDescEditor?->stopJobs();
        $this->prEditDescEditor?->stopJobs();
        $this->prCommentEditor?->stopJobs();
    }

    public function tick(?float $deltaTime = null): bool
    {
        $busy = false;

        if (null !== $this->detailCommitsProcess) {
            $busy = true;
            if (!$this->detailCommitsProcess->isRunning()) {
                $proc = $this->detailCommitsProcess;
                $this->detailCommitsProcess = null;
                $this->detailCommits = $proc->isSuccessful()
                    ? $this->ctx->reader->parseCommitsOutput($proc->getOutput())
                    : [];
            }
        }

        if (null !== $this->detailDiffProcess) {
            $busy = true;
            if (!$this->detailDiffProcess->isRunning()) {
                $proc = $this->detailDiffProcess;
                $this->detailDiffProcess = null;
                $this->detailDiff = $proc->isSuccessful()
                    ? $this->ctx->reader->parseDiffOutput($proc->getOutput())
                    : null;
            }
        }

        if (null !== $this->detailDescJob) {
            $busy = true;
            if (!$this->detailDescJob['process']->isRunning()) {
                $job = $this->detailDescJob;
                $this->detailDescJob = null;
                $proc = $job['process'];
                $lines = $proc->isSuccessful() && '' !== trim($proc->getOutput())
                    ? (preg_split('/\r\n|\r|\n/', rtrim($proc->getOutput(), "\n")) ?: [])
                    : $job['fallback'];
                $this->detailDescCache = ['key' => $job['key'], 'lines' => $lines];
            }
        }

        if (null !== $this->commentRenderJob) {
            $busy = true;
            if (!$this->commentRenderJob['process']->isRunning()) {
                $job = $this->commentRenderJob;
                $this->commentRenderJob = null;
                $proc = $job['process'];
                $lines = $proc->isSuccessful() && '' !== trim($proc->getOutput())
                    ? (preg_split('/\r\n|\r|\n/', rtrim($proc->getOutput(), "\n")) ?: [])
                    : $job['fallback'];
                $this->commentRenderCache[$job['key']] = $lines;
            }
        }

        if (null !== $this->viewingCommitDiffProcess) {
            $busy = true;
            if (!$this->viewingCommitDiffProcess->isRunning()) {
                $proc = $this->viewingCommitDiffProcess;
                $this->viewingCommitDiffProcess = null;
                if ($proc->isSuccessful() && '' !== $proc->getOutput()) {
                    $this->viewingCommitDiff = $this->ctx->reader->parseDiffOutput($proc->getOutput());
                } elseif (null !== $this->viewingCommit) {
                    try {
                        $this->viewingCommitDiff = $this->ctx->reader->getDiff($this->ctx->git, $this->viewingCommit->hash);
                    } catch (\Throwable) {
                        $this->viewingCommitDiff = null;
                    }
                }
            }
        }

        if (null !== $this->prDiffProcess) {
            $busy = true;
            if (!$this->prDiffProcess->isRunning()) {
                $proc = $this->prDiffProcess;
                $this->prDiffProcess = null;
                if ($proc->isSuccessful()) {
                    $raw = $proc->getOutput();
                    $this->prDiffLines = preg_split('/\r\n|\r|\n/', rtrim($raw, "\n")) ?: [];
                    $this->prDiffScroll = 0;
                } else {
                    $this->prDiffOpen = false;
                    $this->ctx->flash = 'Failed to load diff.';
                }
            }
        }
        if ($this->prFormOpen) {
            if ('' === $this->prFormBase && !$this->ctx->isRefsLoading()) {
                $this->populateDefaultPrFormBranches();
            }
            if (null !== $this->prFormDescEditor && $this->prFormDescEditor->tick($deltaTime)) {
                $busy = true;
            }
        }

        if ($this->prEditOpen && null !== $this->prEditDescEditor && $this->prEditDescEditor->tick($deltaTime)) {
            $busy = true;
        }

        if ($this->prCommentOpen && null !== $this->prCommentEditor && $this->prCommentEditor->tick($deltaTime)) {
            $busy = true;
        }

        if ($busy) {
            $this->ctx->spinner->advance($deltaTime);
        }

        return $busy;
    }

    private function isDetailLoading(): bool
    {
        return null !== $this->detailCommitsProcess || null !== $this->detailDiffProcess;
    }

    public function footerText(): string
    {
        if ($this->prCommitDiffOpen || $this->prDiffOpen) {
            return ' ↑/↓ scroll · Esc: back';
        }
        if ($this->prMergeConfirm) {
            return ' y: confirm merge · n/Esc: cancel';
        }
        if ($this->prDeleteConfirm || $this->prDeleteCommentConfirm) {
            return ' y: confirm delete · n/Esc: cancel';
        }
        if ($this->prFormOpen) {
            return ' Tab/↑/↓: field · Enter: next · Ctrl+S: submit · Esc: cancel';
        }
        if ($this->prEditOpen) {
            return ' Tab/↑/↓: field · Ctrl+S: save · Ctrl+P: preview · Esc: cancel';
        }
        if ($this->prCommentOpen) {
            return ' Ctrl+S: save · Ctrl+P: preview · Esc: cancel';
        }
        if ($this->prDetailOpen) {
            if ($this->commentMenuActive) {
                return ' n: new comment · e: edit · d: delete · Esc: cancel';
            }
            $pr = $this->currentDetailPull();
            $statusAction = ($pr?->isOpen() ?? true) ? 's: close' : 's: reopen';
            $mergeAction = ($pr?->isOpen() ?? true) ? 'm: merge · ' : '';

            return ' Tab/1-4: sub-tab · ' . $mergeAction . $statusAction . ' · e: edit · c: comment · d: diff · x: delete · Esc: back';
        }
        if ($this->fuzzyOpen) {
            return ' type: filter · ↑/↓: move · Enter: view · Esc: close';
        }

        return ' /: search · ↑/↓ move · Enter: view · n: new · f: filter · m: merge · Tab: next · q: back';
    }

    public function statusValue(): string
    {
        if ($this->fuzzyOpen) {
            return '' !== $this->fuzzyQuery ? '/' . $this->fuzzyQuery : '[SEARCH]';
        }
        if ($this->prCommitDiffOpen && null !== $this->viewingCommit) {
            return $this->viewingCommit->hash;
        }
        if ($this->prDetailOpen && !$this->prEditOpen && !$this->prFormOpen && !$this->prCommentOpen && null !== ($pr = $this->currentDetailPull())) {
            return sprintf('#%d %s', $pr->number(), $pr->title());
        }

        $pulls = $this->currentPulls();
        if ([] !== $pulls) {
            $idx = min($this->sel + 1, \count($pulls));

            return sprintf('%d/%d', $idx, \count($pulls));
        }

        return '';
    }

    public function statusInfo(): string
    {
        if ($this->prCommitDiffOpen) {
            return 'commit diff';
        }
        if ($this->prFormOpen) {
            return 'new pull request';
        }
        if ($this->prEditOpen) {
            return 'edit pull request';
        }
        if ($this->prCommentOpen) {
            return $this->editingCommentId > 0 ? 'edit comment' : 'new comment';
        }
        if ($this->prDeleteConfirm) {
            return 'confirm delete';
        }
        if ($this->prDeleteCommentConfirm) {
            return 'confirm delete comment';
        }
        if ($this->prDetailOpen) {
            if ($this->commentMenuActive) {
                if (null !== $this->latestEditableComment()) {
                    return 'comment: (n)ew • (e)dit • (d)elete • esc cancel';
                }

                return 'comment: (n)ew • esc cancel';
            }

            return match ($this->detailTab) {
                self::TAB_COMMITS => [] !== $this->detailCommits
                    ? sprintf('commit %d/%d', $this->selectedCommitIdx + 1, \count($this->detailCommits))
                    : 'no commits',
                self::TAB_CHANGES => null !== $this->detailDiff && [] !== $this->detailDiff->files
                    ? sprintf('file %d/%d', $this->selectedFileIdx + 1, \count($this->detailDiff->files))
                    : 'no changes',
                self::TAB_COMMENTS => sprintf('%d comments', \count($this->detailComments)),
                default => null !== ($pr = $this->currentDetailPull())
                    ? strtoupper($pr->status()->value)
                    : '',
            };
        }

        $pulls = $this->fuzzyOpen ? $this->fuzzyPulls() : $this->currentPulls();
        $sel = $this->fuzzyOpen ? $this->fuzzySel : $this->sel;
        $prefix = $this->fuzzyOpen ? '# ' : '';
        $filterSuffix = null !== $this->filterStatus ? ' (' . $this->filterStatus->value . ')' : '';

        return [] === $pulls
            ? ($this->fuzzyOpen ? '# 0/0' : '0/0' . $filterSuffix)
            : $prefix . min($sel + 1, \count($pulls)) . '/' . \count($pulls) . $filterSuffix;
    }

    public function handleKey(string $seq): bool
    {
        if (null === $this->ctx->db || null === $this->ctx->pullRequestQuery) {
            return false;
        }

        if ($this->prDiffOpen) {
            return $this->handlePrDiffKey($seq);
        }

        if ($this->prCommitDiffOpen) {
            return $this->handlePrCommitDiffKey($seq);
        }

        if ($this->prMergeConfirm) {
            return $this->handlePrMergeConfirmKey($seq);
        }

        if ($this->prDeleteConfirm) {
            return $this->handlePrDeleteConfirmKey($seq);
        }

        if ($this->prDeleteCommentConfirm) {
            return $this->handlePrDeleteCommentConfirmKey($seq);
        }

        if ($this->prFormOpen) {
            return $this->handlePrFormKey($seq);
        }

        if ($this->prEditOpen) {
            return $this->handlePrEditKey($seq);
        }

        if ($this->prCommentOpen) {
            return $this->handlePrCommentKey($seq);
        }

        if ($this->prDetailOpen) {
            return $this->handlePrDetailKey($seq);
        }

        if ($this->fuzzyOpen) {
            if ($this->ctx->isPullsLoading()) {
                return $this->handleFuzzyInputKey($seq, 0, true);
            }
            $pulls = $this->fuzzyPulls();

            return $this->handleFuzzyInputKey($seq, \count($pulls), false, function (int $idx) use ($pulls): void {
                $pr = $pulls[$idx] ?? null;
                $this->closeFuzzySearch();
                if (null !== $pr) {
                    $this->openPrDetail($pr);
                }
            });
        }

        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if ('/' === $txt || Keys::CTRL_P === $tok) {
            $this->openFuzzySearch();

            return true;
        }

        return $this->handlePullsTabKey($tok, $txt);
    }

    private function handlePullsTabKey(string $tok, ?string $txt): bool
    {
        if ($this->ctx->isPullsLoading()) {
            return false;
        }
        $pulls = $this->currentPulls();

        if (Keys::ENTER === $tok && [] !== $pulls) {
            $pr = $this->selectedPull();
            if (null !== $pr) {
                $this->openPrDetail($pr);

                return true;
            }
        }

        if ('m' === $txt && [] !== $pulls && null !== $this->ctx->mergePullRequest) {
            $pr = $this->selectedPull();
            if ($pr?->isOpen()) {
                $this->prDetailNumber = $pr->number();
                $this->prMergeConfirm = true;

                return true;
            }
        }

        if ('f' === $txt) {
            $this->filterStatus = match ($this->filterStatus) {
                null => PullRequestStatus::Open,
                PullRequestStatus::Open => PullRequestStatus::Closed,
                PullRequestStatus::Closed => PullRequestStatus::Merged,
                PullRequestStatus::Merged => null,
            };
            $this->sel = 0;

            return true;
        }

        if ('n' === $txt) {
            if (null === $this->ctx->db || null === $this->ctx->openPullRequest) {
                $this->ctx->flash = 'PR creation is unavailable for this repository.';

                return true;
            }
            if (null === $this->ctx->actor) {
                $this->ctx->flash = 'Authentication required to open a pull request.';

                return true;
            }
            $this->openNewPrForm();

            return true;
        }

        return $this->handleListMove($tok, $txt, \count($pulls));
    }

    private function openPrDetail(PullRequest $pr): void
    {
        $this->prDetailOpen = true;
        $this->prDetailNumber = $pr->number();
        $this->detailTab = self::TAB_ABOUT;
        $this->scroll = 0;
        $this->selectedCommitIdx = 0;
        $this->selectedFileIdx = 0;
        $this->expandedFiles = [];
        $this->commentMenuActive = false;
        $this->reloadPrDetailData($pr);
    }

    private function reloadPrDetailData(PullRequest $pr): void
    {
        if (null === $this->ctx->pullRequestQuery || null === $this->ctx->db) {
            return;
        }
        $repoName = $this->ctx->db->name();
        $this->detailDescCache = null;
        if (null !== $this->detailDescJob && $this->detailDescJob['process']->isRunning()) {
            $this->detailDescJob['process']->stop(0);
        }
        $this->detailDescJob = null;

        if (null !== $this->detailCommitsProcess && $this->detailCommitsProcess->isRunning()) {
            $this->detailCommitsProcess->stop(0);
        }
        $this->detailCommitsProcess = null;
        $this->detailCommits = [];
        try {
            $this->detailCommitsProcess = $this->ctx->pullRequestQuery->startCommitsProcess($this->ctx->actor, $repoName, $pr->number());
        } catch (\Throwable) {
            $this->detailCommitsProcess = null;
        }

        if (null !== $this->detailDiffProcess && $this->detailDiffProcess->isRunning()) {
            $this->detailDiffProcess->stop(0);
        }
        $this->detailDiffProcess = null;
        $this->detailDiff = null;
        try {
            $this->detailDiffProcess = $this->ctx->pullRequestQuery->startDiffProcess($this->ctx->actor, $repoName, $pr->number());
        } catch (\Throwable) {
            $this->detailDiffProcess = null;
        }

        try {
            $this->detailComments = $this->ctx->pullRequestQuery->comments($this->ctx->actor, $repoName, $pr->number());
        } catch (\Throwable) {
            $this->detailComments = [];
        }
        try {
            $this->detailMergeable = $pr->isOpen() && $this->ctx->pullRequestQuery->canMerge($this->ctx->actor, $repoName, $pr->number());
        } catch (\Throwable) {
            $this->detailMergeable = false;
        }
    }

    private function openNewPrForm(): void
    {
        $this->prFormOpen = true;
        $this->prFormField = 0;
        $this->prFormError = null;
        $this->prFormHead = '';
        $this->prFormBase = '';
        $this->prFormTitle = '';
        $this->headGhostActive = false;
        $this->headGhostIndex = 0;
        $this->baseGhostActive = false;
        $this->baseGhostIndex = 0;
        $this->prFormDescEditor = new Editor();
        $this->prFormDescEditor->setSpinner($this->ctx->spinner);
        $this->prFormDescEditor->setContent('');
        $this->prFormDescEditor->enableMarkdownPreview($this->ctx->renderer, 'description.md', 'off');

        if (!$this->ctx->isRefsLoading()) {
            $this->populateDefaultPrFormBranches();
        }
    }

    private function populateDefaultPrFormBranches(): void
    {
        $branches = $this->ctx->branchList();
        $defaultBase = $this->ctx->git->defaultBranch;
        $this->prFormBase = \in_array($defaultBase, $branches, true) ? $defaultBase : ($branches[0] ?? $defaultBase);

        if ('' === $this->prFormHead) {
            $candidateHead = null;
            if (\in_array($this->ctx->ref, $branches, true) && $this->ctx->ref !== $this->prFormBase) {
                $candidateHead = $this->ctx->ref;
            } else {
                foreach ($branches as $b) {
                    if ($b !== $this->prFormBase) {
                        $candidateHead = $b;
                        break;
                    }
                }
            }
            $this->prFormHead = $candidateHead ?? '';
        }
    }

    /**
     * @return list<string>
     */
    private function branchSuggestions(string $input): array
    {
        if ($this->ctx->isRefsLoading()) {
            return [];
        }
        $all = $this->ctx->branchList();
        $trimmed = trim($input);
        if ('' === $trimmed) {
            return $all;
        }
        $matches = [];
        foreach ($all as $b) {
            if (str_starts_with(strtolower($b), strtolower($trimmed))) {
                $matches[] = $b;
            }
        }

        return $matches;
    }

    private function handlePrFormKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if (Keys::ESCAPE === $tok) {
            $this->prFormOpen = false;
            $this->prFormError = null;

            return true;
        }

        if ($this->isCtrlS($seq)) {
            $this->submitNewPr();

            return true;
        }

        if (Keys::TAB === $tok) {
            $this->prFormField = ($this->prFormField + 1) % 4;
            $this->headGhostActive = false;
            $this->baseGhostActive = false;

            return true;
        }

        if (Keys::BACKTAB === $tok) {
            $this->prFormField = ($this->prFormField + 3) % 4;
            $this->headGhostActive = false;
            $this->baseGhostActive = false;

            return true;
        }

        if (0 === $this->prFormField || 1 === $this->prFormField) {
            $isHead = 0 === $this->prFormField;
            $currentVal = $isHead ? $this->prFormHead : $this->prFormBase;
            $ghostActive = $isHead ? $this->headGhostActive : $this->baseGhostActive;
            $ghostIdx = $isHead ? $this->headGhostIndex : $this->baseGhostIndex;
            $suggestions = $this->branchSuggestions($currentVal);

            if (Keys::DOWN === $tok) {
                if ('' === trim($currentVal) && $ghostActive && \count($suggestions) > 1) {
                    $newIdx = ($ghostIdx + 1) % \count($suggestions);
                    if ($isHead) {
                        $this->headGhostIndex = $newIdx;
                    } else {
                        $this->baseGhostIndex = $newIdx;
                    }

                    return true;
                }
                $this->prFormField = ($this->prFormField + 1) % 4;
                $this->headGhostActive = false;
                $this->baseGhostActive = false;

                return true;
            }

            if (Keys::UP === $tok) {
                if ('' === trim($currentVal) && $ghostActive && \count($suggestions) > 1) {
                    $newIdx = ($ghostIdx - 1 + \count($suggestions)) % \count($suggestions);
                    if ($isHead) {
                        $this->headGhostIndex = $newIdx;
                    } else {
                        $this->baseGhostIndex = $newIdx;
                    }

                    return true;
                }
                $this->prFormField = ($this->prFormField + 3) % 4;
                $this->headGhostActive = false;
                $this->baseGhostActive = false;

                return true;
            }

            if (Keys::ENTER === $tok) {
                if ('' === trim($currentVal) && $ghostActive && isset($suggestions[$ghostIdx])) {
                    if ($isHead) {
                        $this->prFormHead = $suggestions[$ghostIdx];
                        $this->headGhostActive = false;
                    } else {
                        $this->prFormBase = $suggestions[$ghostIdx];
                        $this->baseGhostActive = false;
                    }
                } elseif ('' !== trim($currentVal) && [] !== $suggestions) {
                    if ($isHead) {
                        $this->prFormHead = $suggestions[0];
                    } else {
                        $this->prFormBase = $suggestions[0];
                    }
                }
                $this->prFormField = ($this->prFormField + 1) % 4;

                return true;
            }

            if (Keys::RIGHT === $tok) {
                if ('' === trim($currentVal)) {
                    $all = $this->ctx->branchList();
                    if (!$ghostActive && [] !== $all) {
                        if ($isHead) {
                            $this->headGhostActive = true;
                            $this->headGhostIndex = 0;
                        } else {
                            $this->baseGhostActive = true;
                            $this->baseGhostIndex = 0;
                        }

                        return true;
                    }
                    if ($ghostActive && isset($all[$ghostIdx])) {
                        $next = $this->nextBranchToken('', $all[$ghostIdx], $all);
                        if ($isHead) {
                            $this->prFormHead = $next;
                            $this->headGhostActive = false;
                        } else {
                            $this->prFormBase = $next;
                            $this->baseGhostActive = false;
                        }

                        return true;
                    }
                } elseif ([] !== $suggestions && \strlen($currentVal) < \strlen($suggestions[0])) {
                    $next = $this->nextBranchToken($currentVal, $suggestions[0], $suggestions);
                    if ($isHead) {
                        $this->prFormHead = $next;
                    } else {
                        $this->prFormBase = $next;
                    }

                    return true;
                }
                $this->cycleBranch($this->prFormField, 1);

                return true;
            }

            if (Keys::SPACE === $tok) {
                $this->cycleBranch($this->prFormField, 1);

                return true;
            }

            if (Keys::LEFT === $tok) {
                $this->cycleBranch($this->prFormField, -1);

                return true;
            }

            if (Keys::BACKSPACE === $tok) {
                if ($isHead) {
                    $this->prFormHead = mb_substr($this->prFormHead, 0, -1);
                    $this->headGhostActive = false;
                } else {
                    $this->prFormBase = mb_substr($this->prFormBase, 0, -1);
                    $this->baseGhostActive = false;
                }

                return true;
            }

            if (null !== $txt && '' !== $txt) {
                if ($isHead) {
                    $this->prFormHead .= $txt;
                    $this->headGhostActive = false;
                } else {
                    $this->prFormBase .= $txt;
                    $this->baseGhostActive = false;
                }

                return true;
            }

            return true;
        }

        if (2 === $this->prFormField) {
            if (Keys::DOWN === $tok || Keys::ENTER === $tok) {
                $this->prFormField = 3;

                return true;
            }
            if (Keys::UP === $tok) {
                $this->prFormField = 1;

                return true;
            }
            if (Keys::BACKSPACE === $tok) {
                $this->prFormTitle = mb_substr($this->prFormTitle, 0, -1);

                return true;
            }
            if (null !== $txt) {
                $this->prFormTitle .= $txt;

                return true;
            }

            return true;
        }

        if (3 === $this->prFormField) {
            $this->prFormDescEditor ??= new Editor();
            if (Keys::UP === $tok && 0 === $this->prFormDescEditor->line() && !$this->prFormDescEditor->isFullPreview()) {
                $this->prFormField = 2;

                return true;
            }

            return $this->prFormDescEditor->handleKey($seq);
        }

        return true;
    }

    /**
     * @param list<string> $matches
     */
    private function nextBranchToken(string $currVal, string $suggestion, array $matches): string
    {
        if (\strlen($currVal) >= \strlen($suggestion)) {
            return $currVal;
        }
        if (\count($matches) > 1) {
            $lcp = $matches[0];
            foreach (\array_slice($matches, 1) as $m) {
                $i = 0;
                $max = min(\strlen($lcp), \strlen($m));
                while ($i < $max && strtolower($lcp[$i]) === strtolower($m[$i])) {
                    ++$i;
                }
                $lcp = substr($lcp, 0, $i);
            }
            if (\strlen($lcp) > \strlen($currVal)) {
                return $lcp;
            }
        }
        $rem = substr($suggestion, \strlen($currVal));
        if (1 === preg_match('/^[^\/\-_.: ]+[\/\-_.: ]/', $rem, $m)) {
            return $currVal . $m[0];
        }

        return $suggestion;
    }

    private function cycleBranch(int $field, int $delta): void
    {
        $branches = $this->ctx->branchList();
        if ([] === $branches) {
            return;
        }
        $current = 0 === $field ? $this->prFormHead : $this->prFormBase;
        $idx = array_search($current, $branches, true);
        if (false === $idx) {
            $idx = 0;
        } else {
            $idx = ($idx + $delta + \count($branches)) % \count($branches);
        }
        if (0 === $field) {
            $this->prFormHead = $branches[$idx];
        } else {
            $this->prFormBase = $branches[$idx];
        }
    }

    private function submitNewPr(): void
    {
        if (null === $this->ctx->db || null === $this->ctx->openPullRequest || null === $this->ctx->actor) {
            $this->prFormError = 'PR creation is unavailable.';
            $this->ctx->flash = $this->prFormError;

            return;
        }

        $head = trim($this->prFormHead);
        if ('' === $head) {
            $this->prFormError = 'source branch cannot be empty';
            $this->ctx->flash = 'Source branch cannot be empty.';

            return;
        }

        $base = trim($this->prFormBase);
        if ('' === $base) {
            $this->prFormError = 'target branch cannot be empty';
            $this->ctx->flash = 'Target branch cannot be empty.';

            return;
        }

        $title = trim($this->prFormTitle);
        if ('' === $title) {
            $this->prFormError = 'title cannot be empty';
            $this->ctx->flash = 'Pull request title cannot be empty.';

            return;
        }

        $desc = trim($this->prFormDescEditor?->value() ?? '');

        try {
            $pr = ($this->ctx->openPullRequest)(
                $this->ctx->actor,
                $this->ctx->db->name(),
                $title,
                $desc,
                $base,
                $head,
            );
            $this->ctx->flash = sprintf('Opened PR #%d: %s', $pr->number(), $pr->title());
            $this->ctx->pullsCache = null;
            $this->prFormOpen = false;
            $this->prFormError = null;
            $this->filterStatus = PullRequestStatus::Open;
            $this->openPrDetail($pr);
        } catch (AccessDenied | NotFound | Conflict | InvalidInput | \RuntimeException $e) {
            $this->prFormError = $e->getMessage();
            $this->ctx->flash = $e->getMessage();
        }
    }

    private function handlePrDetailKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if ($this->commentMenuActive) {
            if (Keys::ESCAPE === $tok) {
                $this->commentMenuActive = false;

                return true;
            }
            if ('n' === $txt) {
                $this->openCommentCompose(0, '');

                return true;
            }
            if ('e' === $txt) {
                $target = $this->latestEditableComment();
                if (null !== $target) {
                    $this->openCommentCompose($target->id() ?? 0, $target->body());
                } else {
                    $this->commentMenuActive = false;
                    $this->ctx->flash = 'No editable comment found.';
                }

                return true;
            }
            if ('d' === $txt) {
                $target = $this->latestEditableComment();
                if (null !== $target) {
                    $this->commentMenuActive = false;
                    $this->editingCommentId = $target->id() ?? 0;
                    $this->prDeleteCommentConfirm = true;
                } else {
                    $this->commentMenuActive = false;
                    $this->ctx->flash = 'No deletable comment found.';
                }

                return true;
            }

            return true;
        }

        if (Keys::ESCAPE === $tok || Keys::BACKSPACE === $tok || 'q' === $txt) {
            $this->prDetailOpen = false;
            $this->prDetailNumber = null;

            return true;
        }

        $pr = $this->currentDetailPull();
        if (null === $pr) {
            $this->prDetailOpen = false;

            return true;
        }

        // Sub-tab navigation: Tab / Shift+Tab / 1-4
        if (Keys::TAB === $tok) {
            $this->detailTab = ($this->detailTab + 1) % 4;
            $this->scroll = 0;

            return true;
        }
        if (Keys::BACKTAB === $tok) {
            $this->detailTab = ($this->detailTab + 3) % 4;
            $this->scroll = 0;

            return true;
        }
        if (null !== $txt && \in_array($txt, ['1', '2', '3', '4'], true)) {
            $this->detailTab = ((int) $txt) - 1;
            $this->scroll = 0;

            return true;
        }

        if ('m' === $txt && $pr->isOpen() && null !== $this->ctx->mergePullRequest) {
            $this->prMergeConfirm = true;

            return true;
        }

        if ('d' === $txt && null !== $this->ctx->pullRequestQuery && null !== $this->ctx->db) {
            if ($this->canDeleteOrEditPr($pr) && null !== $this->ctx->deletePullRequest && self::TAB_ABOUT !== $this->detailTab) {
                // Keep 'd' for diff as tested in functional tests, while 'x' or 'D' deletes
            }
            if (null !== $this->detailDiff) {
                $raw = $this->detailDiff->rawDiff;
                $this->prDiffLines = preg_split('/\r\n|\r|\n/', rtrim($raw, "\n")) ?: [];
                $this->prDiffScroll = 0;
                $this->prDiffOpen = true;

                return true;
            }
            if (null !== $this->prDiffProcess && $this->prDiffProcess->isRunning()) {
                $this->prDiffProcess->stop(0);
            }
            try {
                $this->prDiffProcess = $this->ctx->pullRequestQuery->startDiffProcess($this->ctx->actor, $this->ctx->db->name(), $pr->number());
                $this->prDiffLines = [];
                $this->prDiffScroll = 0;
                $this->prDiffOpen = true;
            } catch (\Throwable $e) {
                $this->prDiffProcess = null;
                $this->ctx->flash = 'Failed to load diff: ' . $e->getMessage();
            }

            return true;
        }

        if ('s' === $txt && null !== $this->ctx->db) {
            if (null === $this->ctx->actor) {
                $this->ctx->flash = 'Authentication required.';

                return true;
            }
            if ($pr->isOpen()) {
                if (null === $this->ctx->closePullRequest) {
                    $this->ctx->flash = 'Close PR unavailable.';

                    return true;
                }
                try {
                    ($this->ctx->closePullRequest)($this->ctx->actor, $this->ctx->db->name(), $pr->number());
                    $this->ctx->flash = sprintf('Closed PR #%d', $pr->number());
                    $this->ctx->pullsCache = null;
                } catch (AccessDenied | NotFound | Conflict | InvalidInput | \RuntimeException $e) {
                    $this->ctx->flash = $e->getMessage();
                }

                return true;
            }
            if (PullRequestStatus::Closed === $pr->status()) {
                if (null === $this->ctx->reopenPullRequest) {
                    $this->ctx->flash = 'Reopen PR unavailable.';

                    return true;
                }
                try {
                    ($this->ctx->reopenPullRequest)($this->ctx->actor, $this->ctx->db->name(), $pr->number());
                    $this->ctx->flash = sprintf('Reopened PR #%d', $pr->number());
                    $this->ctx->pullsCache = null;
                } catch (AccessDenied | NotFound | Conflict | InvalidInput | \RuntimeException $e) {
                    $this->ctx->flash = $e->getMessage();
                }

                return true;
            }

            return true;
        }

        if ('e' === $txt && null !== $this->ctx->updatePullRequest && null !== $this->ctx->db) {
            if (null === $this->ctx->actor) {
                $this->ctx->flash = 'Authentication required.';

                return true;
            }
            $this->prEditOpen = true;
            $this->prEditField = 0;
            $this->prEditError = null;
            $this->prEditTitle = $pr->title();
            $this->prEditDescEditor = new Editor();
            $this->prEditDescEditor->setSpinner($this->ctx->spinner);
            $this->prEditDescEditor->setContent($pr->description());
            $this->prEditDescEditor->enableMarkdownPreview($this->ctx->renderer, 'description.md', 'off');

            return true;
        }

        if ('c' === $txt && null !== $this->ctx->commentPullRequest && null !== $this->ctx->db) {
            if (null === $this->ctx->actor) {
                $this->ctx->flash = 'Authentication required.';

                return true;
            }
            if (null !== $this->latestEditableComment()) {
                $this->commentMenuActive = true;
            } else {
                $this->openCommentCompose(0, '');
            }

            return true;
        }

        if (('x' === $txt || 'D' === $txt) && null !== $this->ctx->deletePullRequest && null !== $this->ctx->db) {
            if (null === $this->ctx->actor) {
                $this->ctx->flash = 'Authentication required.';

                return true;
            }
            $this->prDeleteConfirm = true;

            return true;
        }

        // Sub-tab specific navigation
        if (self::TAB_COMMITS === $this->detailTab && [] !== $this->detailCommits) {
            if (Keys::UP === $tok || 'k' === $txt) {
                if ($this->selectedCommitIdx > 0) {
                    --$this->selectedCommitIdx;
                    $this->scrollToSelectedCommit();
                }

                return true;
            }
            if (Keys::DOWN === $tok || 'j' === $txt) {
                if ($this->selectedCommitIdx < \count($this->detailCommits) - 1) {
                    ++$this->selectedCommitIdx;
                    $this->scrollToSelectedCommit();
                }

                return true;
            }
            if (Keys::ENTER === $tok) {
                $commit = $this->detailCommits[$this->selectedCommitIdx] ?? null;
                if (null !== $commit) {
                    $this->openCommitDiff($commit);
                }

                return true;
            }
        }

        if (self::TAB_CHANGES === $this->detailTab && null !== $this->detailDiff && [] !== $this->detailDiff->files) {
            $filesCount = \count($this->detailDiff->files);
            if (Keys::UP === $tok || 'k' === $txt) {
                $this->moveDiffFocus(false);

                return true;
            }
            if (Keys::DOWN === $tok || 'j' === $txt) {
                $this->moveDiffFocus(true);

                return true;
            }
            if (Keys::ENTER === $tok || Keys::SPACE === $tok) {
                if ($this->selectedFileIdx >= 0 && $this->selectedFileIdx < $filesCount) {
                    $this->expandedFiles[$this->selectedFileIdx] = !($this->expandedFiles[$this->selectedFileIdx] ?? false);
                }

                return true;
            }
            if ('a' === $txt) {
                $allExpanded = true;
                for ($i = 0; $i < $filesCount; ++$i) {
                    if (!($this->expandedFiles[$i] ?? false)) {
                        $allExpanded = false;
                        break;
                    }
                }
                for ($i = 0; $i < $filesCount; ++$i) {
                    $this->expandedFiles[$i] = !$allExpanded;
                }

                return true;
            }
        }

        return $this->handleDocScroll($tok, $txt, 200);
    }

    private function openCommitDiff(Commit $commit): void
    {
        $this->viewingCommit = $commit;
        $this->viewingCommitDiff = null;
        if (null !== $this->viewingCommitDiffProcess && $this->viewingCommitDiffProcess->isRunning()) {
            $this->viewingCommitDiffProcess->stop(0);
        }
        try {
            $this->viewingCommitDiffProcess = $this->ctx->reader->startCommitDiffProcess($this->ctx->git, $commit->hash);
        } catch (\Throwable) {
            $this->viewingCommitDiffProcess = null;
        }
        $this->commitDiffScroll = 0;
        $this->prCommitDiffOpen = true;
    }

    private function handlePrCommitDiffKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if (Keys::ESCAPE === $tok || Keys::BACKSPACE === $tok || 'q' === $txt) {
            if (null !== $this->viewingCommitDiffProcess && $this->viewingCommitDiffProcess->isRunning()) {
                $this->viewingCommitDiffProcess->stop(0);
            }
            $this->viewingCommitDiffProcess = null;
            $this->prCommitDiffOpen = false;
            $this->viewingCommit = null;
            $this->viewingCommitDiff = null;

            return true;
        }
        if (null !== $this->viewingCommitDiffProcess) {
            return false;
        }
        $lines = $this->renderPrCommitDetailLines($this->rows > 0 ? 80 : 80);
        $maxScroll = max(0, \count($lines) - max(1, $this->rows));
        if (Keys::UP === $tok || 'k' === $txt) {
            $this->commitDiffScroll = max(0, $this->commitDiffScroll - 1);

            return true;
        }
        if (Keys::DOWN === $tok || 'j' === $txt) {
            $this->commitDiffScroll = min($maxScroll, $this->commitDiffScroll + 1);

            return true;
        }

        return false;
    }

    private function openCommentCompose(int $commentId, string $body): void
    {
        $this->commentMenuActive = false;
        $this->editingCommentId = $commentId;
        $this->prCommentOpen = true;
        $this->prCommentEditor = new Editor();
        $this->prCommentEditor->setSpinner($this->ctx->spinner);
        $this->prCommentEditor->setContent($body);
        $this->prCommentEditor->enableMarkdownPreview($this->ctx->renderer, 'comment.md', 'off');
    }

    private function latestEditableComment(): ?Comment
    {
        if (null === $this->ctx->actor || [] === $this->detailComments) {
            return null;
        }
        $isAdmin = $this->ctx->actor->isAdmin();
        for ($i = \count($this->detailComments) - 1; $i >= 0; --$i) {
            $c = $this->detailComments[$i];
            if ($c->author()->id() === $this->ctx->actor->id() || $isAdmin) {
                return $c;
            }
        }

        return null;
    }

    private function canDeleteOrEditPr(PullRequest $pr): bool
    {
        if (null === $this->ctx->actor) {
            return false;
        }

        return $this->ctx->actor->isAdmin() || $pr->author()->id() === $this->ctx->actor->id();
    }

    private function handlePrEditKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if (Keys::ESCAPE === $tok) {
            $this->prEditOpen = false;
            $this->prEditError = null;

            return true;
        }

        if ($this->isCtrlS($seq)) {
            $this->submitEditPr();

            return true;
        }

        if (Keys::TAB === $tok || Keys::BACKTAB === $tok) {
            $this->prEditField = ($this->prEditField + 1) % 2;

            return true;
        }

        if (0 === $this->prEditField) {
            if (Keys::ENTER === $tok || Keys::DOWN === $tok) {
                $this->prEditField = 1;

                return true;
            }
            if (Keys::BACKSPACE === $tok) {
                $this->prEditTitle = mb_substr($this->prEditTitle, 0, -1);

                return true;
            }
            if (null !== $txt) {
                $this->prEditTitle .= $txt;

                return true;
            }

            return true;
        }

        if (1 === $this->prEditField) {
            $this->prEditDescEditor ??= new Editor();
            if (Keys::UP === $tok && 0 === $this->prEditDescEditor->line() && !$this->prEditDescEditor->isFullPreview()) {
                $this->prEditField = 0;

                return true;
            }

            return $this->prEditDescEditor->handleKey($seq);
        }

        return true;
    }

    private function submitEditPr(): void
    {
        $pr = $this->currentDetailPull();
        if (null === $pr || null === $this->ctx->updatePullRequest || null === $this->ctx->db || null === $this->ctx->actor) {
            $this->ctx->flash = 'Update PR unavailable.';

            return;
        }

        $title = trim($this->prEditTitle);
        if ('' === $title) {
            $this->prEditError = 'title cannot be empty';
            $this->ctx->flash = 'Pull request title cannot be empty.';

            return;
        }

        $desc = trim($this->prEditDescEditor?->value() ?? '');

        try {
            $updated = ($this->ctx->updatePullRequest)(
                $this->ctx->actor,
                $this->ctx->db->name(),
                $pr->number(),
                $title,
                $desc,
            );
            $this->ctx->flash = sprintf('Updated PR #%d', $pr->number());
            $this->ctx->pullsCache = null;
            $this->prEditOpen = false;
            $this->prEditError = null;
            $this->reloadPrDetailData($updated);
        } catch (AccessDenied | NotFound | Conflict | InvalidInput | \RuntimeException $e) {
            $this->prEditError = $e->getMessage();
            $this->ctx->flash = $e->getMessage();
        }
    }

    private function handlePrCommentKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);

        if (Keys::ESCAPE === $tok) {
            $this->prCommentOpen = false;
            $this->editingCommentId = 0;

            return true;
        }

        if ($this->isCtrlS($seq)) {
            $this->submitComment();

            return true;
        }

        $this->prCommentEditor ??= new Editor();

        return $this->prCommentEditor->handleKey($seq);
    }

    private function submitComment(): void
    {
        $pr = $this->currentDetailPull();
        if (null === $pr || null === $this->ctx->commentPullRequest || null === $this->ctx->db || null === $this->ctx->actor) {
            $this->ctx->flash = 'Comment unavailable.';

            return;
        }

        $body = trim($this->prCommentEditor?->value() ?? '');
        if ('' === $body) {
            $this->ctx->flash = 'Comment body cannot be empty.';

            return;
        }

        try {
            if ($this->editingCommentId > 0) {
                $this->ctx->commentPullRequest->update(
                    $this->ctx->actor,
                    $this->ctx->db->name(),
                    $pr->number(),
                    $this->editingCommentId,
                    $body,
                );
                $this->ctx->flash = 'Comment updated.';
            } else {
                ($this->ctx->commentPullRequest)(
                    $this->ctx->actor,
                    $this->ctx->db->name(),
                    $pr->number(),
                    $body,
                );
                $this->ctx->flash = 'Comment added.';
            }
            $this->ctx->pullsCache = null;
            $this->prCommentOpen = false;
            $this->editingCommentId = 0;
            $this->reloadPrDetailData($pr);
        } catch (AccessDenied | NotFound | Conflict | InvalidInput | \RuntimeException $e) {
            $this->ctx->flash = $e->getMessage();
        }
    }

    private function handlePrDeleteCommentConfirmKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if ('y' === $txt || 'Y' === $txt) {
            $this->confirmDeleteComment();

            return true;
        }

        if ('n' === $txt || 'N' === $txt || Keys::ESCAPE === $tok) {
            $this->prDeleteCommentConfirm = false;
            $this->editingCommentId = 0;

            return true;
        }

        return true;
    }

    private function confirmDeleteComment(): void
    {
        $pr = $this->currentDetailPull();
        if (null === $pr || null === $this->ctx->commentPullRequest || null === $this->ctx->db || null === $this->ctx->actor || $this->editingCommentId <= 0) {
            $this->prDeleteCommentConfirm = false;
            $this->editingCommentId = 0;

            return;
        }

        try {
            $this->ctx->commentPullRequest->delete(
                $this->ctx->actor,
                $this->ctx->db->name(),
                $pr->number(),
                $this->editingCommentId,
            );
            $this->ctx->flash = 'Comment deleted.';
            $this->prDeleteCommentConfirm = false;
            $this->editingCommentId = 0;
            $this->reloadPrDetailData($pr);
        } catch (AccessDenied | NotFound | Conflict | InvalidInput | \RuntimeException $e) {
            $this->ctx->flash = $e->getMessage();
            $this->prDeleteCommentConfirm = false;
            $this->editingCommentId = 0;
        }
    }

    private function handlePrMergeConfirmKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if ('y' === $txt || 'Y' === $txt) {
            $this->confirmMerge();

            return true;
        }

        if ('n' === $txt || 'N' === $txt || Keys::ESCAPE === $tok) {
            $this->prMergeConfirm = false;

            return true;
        }

        return true;
    }

    private function confirmMerge(): void
    {
        $pr = $this->currentDetailPull() ?? $this->selectedPull();
        if (null === $pr || null === $this->ctx->mergePullRequest || null === $this->ctx->db) {
            $this->prMergeConfirm = false;

            return;
        }

        try {
            ($this->ctx->mergePullRequest)($this->ctx->actor, $this->ctx->db->name(), $pr->number());
            $this->ctx->flash = sprintf('Merged PR #%d', $pr->number());
            $this->ctx->pullsCache = null;
            $this->prMergeConfirm = false;
            $this->prDetailOpen = false;
            $this->prDetailNumber = null;
        } catch (\Throwable $e) {
            $this->ctx->flash = 'Merge failed: ' . $e->getMessage();
            $this->prMergeConfirm = false;
        }
    }

    private function handlePrDeleteConfirmKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if ('y' === $txt || 'Y' === $txt) {
            $this->confirmDelete();

            return true;
        }

        if ('n' === $txt || 'N' === $txt || Keys::ESCAPE === $tok) {
            $this->prDeleteConfirm = false;

            return true;
        }

        return true;
    }

    private function confirmDelete(): void
    {
        $pr = $this->currentDetailPull();
        if (null === $pr || null === $this->ctx->deletePullRequest || null === $this->ctx->db || null === $this->ctx->actor) {
            $this->prDeleteConfirm = false;

            return;
        }

        try {
            ($this->ctx->deletePullRequest)($this->ctx->actor, $this->ctx->db->name(), $pr->number());
            $this->ctx->flash = sprintf('Deleted PR #%d', $pr->number());
            $this->ctx->pullsCache = null;
            $this->prDeleteConfirm = false;
            $this->prDetailOpen = false;
            $this->prDetailNumber = null;
        } catch (AccessDenied | NotFound | Conflict | InvalidInput | \RuntimeException $e) {
            $this->ctx->flash = $e->getMessage();
            $this->prDeleteConfirm = false;
        }
    }

    private function handlePrDiffKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if (Keys::ESCAPE === $tok || 'q' === $txt) {
            if (null !== $this->prDiffProcess && $this->prDiffProcess->isRunning()) {
                $this->prDiffProcess->stop(0);
            }
            $this->prDiffProcess = null;
            $this->prDiffOpen = false;

            return true;
        }
        if (null !== $this->prDiffProcess) {
            return false;
        }

        $total = \count($this->prDiffLines);
        if (Keys::UP === $tok || 'k' === $txt) {
            $this->prDiffScroll = max(0, $this->prDiffScroll - 1);
        } elseif (Keys::DOWN === $tok || 'j' === $txt) {
            $this->prDiffScroll = max(0, min($this->prDiffScroll + 1, max(0, $total - 1)));
        } else {
            return false;
        }

        return true;
    }

    /**
     * @return list<PullRequest>
     */
    private function currentPulls(): array
    {
        if (null === $this->ctx->db || null === $this->ctx->pullRequestQuery) {
            return [];
        }

        $pulls = $this->ctx->pullList();
        if (null === $this->filterStatus) {
            return $pulls;
        }

        return array_values(array_filter($pulls, fn(PullRequest $pr): bool => $pr->status() === $this->filterStatus));
    }

    /**
     * @return list<PullRequest>
     */
    private function fuzzyPulls(): array
    {
        $pulls = $this->currentPulls();
        $query = trim($this->fuzzyQuery);
        if ('' === $query) {
            return $pulls;
        }

        $scored = [];
        foreach ($pulls as $idx => $pr) {
            $score = self::fuzzyScoreFields($query, [
                ['text' => '#' . $pr->number(), 'weight' => 3],
                ['text' => (string) $pr->number(), 'weight' => 2],
                ['text' => $pr->title(), 'weight' => 3],
                ['text' => $pr->headRef(), 'weight' => 2],
                ['text' => $pr->baseRef(), 'weight' => 2],
                ['text' => $pr->author()->username(), 'weight' => 2],
                ['text' => $pr->status()->value, 'weight' => 1],
                ['text' => $pr->description(), 'weight' => 1],
            ]);
            if (null !== $score) {
                $scored[] = ['pr' => $pr, 'score' => $score, 'idx' => $idx];
            }
        }

        usort($scored, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return $a['idx'] <=> $b['idx'];
        });

        return array_map(static fn(array $item): PullRequest => $item['pr'], $scored);
    }

    private function selectedPull(): ?PullRequest
    {
        $pulls = $this->fuzzyOpen ? $this->fuzzyPulls() : $this->currentPulls();
        $sel = $this->fuzzyOpen ? $this->fuzzySel : $this->sel;

        return $pulls[min($sel, max(0, \count($pulls) - 1))] ?? null;
    }

    private function currentDetailPull(): ?PullRequest
    {
        if (null === $this->prDetailNumber) {
            return $this->selectedPull();
        }

        foreach ($this->ctx->pullList() as $pr) {
            if ($pr->number() === $this->prDetailNumber) {
                return $pr;
            }
        }

        if (null !== $this->ctx->pullRequestQuery && null !== $this->ctx->db) {
            try {
                return $this->ctx->pullRequestQuery->get($this->ctx->actor, $this->ctx->db->name(), $this->prDetailNumber);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function isCtrlS(string $seq): bool
    {
        if ("\x13" === $seq) {
            return true;
        }

        $this->keyParser ??= new KeyParser();

        return 'ctrl+s' === ($this->keyParser->parse($seq)['key'] ?? null);
    }

    // --- rendering ---------------------------------------------------------

    public function onClick(int $line, int $col, bool $nav = false): bool
    {
        if (!isset($this->rowToIndex[$line])) {
            return false;
        }
        $idx = $this->rowToIndex[$line];
        $pulls = $this->fuzzyOpen ? $this->fuzzyPulls() : $this->currentPulls();
        if (!isset($pulls[$idx])) {
            return false;
        }
        $cur = $this->fuzzyOpen ? $this->fuzzySel : $this->sel;
        if ($nav || $idx === $cur) {
            if ($this->fuzzyOpen) {
                $this->closeFuzzySearch();
            }
            $this->openPrDetail($pulls[$idx]);
        } elseif ($this->fuzzyOpen) {
            $this->fuzzySel = $idx;
        } else {
            $this->sel = $idx;
        }

        return true;
    }

    public function paintBody(int $cols, int $rows): array
    {
        $this->rows = max(1, $rows);
        $this->rowToIndex = [];

        if (null === $this->ctx->db || null === $this->ctx->pullRequestQuery) {
            return $this->messageBody('Pull requests are unavailable for local repositories.', $cols);
        }

        if ($this->prDiffOpen) {
            $header = "\e[2m  Diff for PR #" . ($this->currentDetailPull()?->number() ?? 0) . "\e[0m";
            if (null !== $this->prDiffProcess) {
                return array_merge([$header], Screen::spinnerBody($this->ctx->spinner, 'Loading diff...', $cols));
            }
            $prevScroll = $this->scroll;
            $this->scroll = $this->prDiffScroll;
            $win = $this->docWindow($this->colourDiff($this->prDiffLines), $cols, max(1, $rows - 1));
            $this->scroll = $prevScroll;

            return array_merge([$header], $win);
        }

        if ($this->prCommitDiffOpen) {
            if (null !== $this->viewingCommitDiffProcess) {
                return Screen::spinnerBody($this->ctx->spinner, 'Loading commit diff...', $cols);
            }
            $lines = $this->renderPrCommitDetailLines($cols);
            $prevScroll = $this->scroll;
            $this->scroll = $this->commitDiffScroll;
            $win = $this->docWindow($lines, $cols, $rows);
            $this->scroll = $prevScroll;

            return $win;
        }

        if ($this->prMergeConfirm) {
            $pr = $this->currentDetailPull() ?? $this->selectedPull();
            $lines = [
                '',
                '  ' . Screen::badge('Merge pull request', Theme::BG, Theme::GREEN),
                '',
                '  ' . Theme::style(sprintf('Merge PR #%d: %s?', $pr?->number() ?? 0, $pr?->title() ?? ''), Theme::BLUE, bold: true),
                '  ' . Theme::style(sprintf('into base branch "%s"', $pr?->baseRef() ?? ''), Theme::MUTED),
                '',
                '  ' . Theme::style('y to confirm • n/esc to cancel', Theme::MUTED, italic: true),
                '',
            ];
            $box = Screen::box('', $lines, min(60, max(34, $cols - 4)), Theme::BLUE);

            return Screen::centerBlock($box, $cols, $rows);
        }

        if ($this->prDeleteConfirm) {
            $pr = $this->currentDetailPull();
            $lines = [
                '',
                '  ' . Screen::badge('Delete pull request', Theme::BG, Theme::SELECTOR),
                '',
                '  ' . Theme::style(sprintf('Permanently delete PR #%d?', $pr?->number() ?? 0), Theme::BLUE, bold: true),
                '',
                '  ' . Theme::style('y to confirm • n/esc to cancel', Theme::MUTED, italic: true),
                '',
            ];
            $box = Screen::box('', $lines, min(50, max(34, $cols - 4)), Theme::SELECTOR);

            return Screen::centerBlock($box, $cols, $rows);
        }

        if ($this->prDeleteCommentConfirm) {
            $lines = [
                '',
                '  ' . Screen::badge('Delete comment', Theme::BG, Theme::SELECTOR),
                '',
                '  ' . Theme::style('Permanently delete this comment?', Theme::BLUE, bold: true),
                '',
                '  ' . Theme::style('y to confirm • n/esc to cancel', Theme::MUTED, italic: true),
                '',
            ];
            $box = Screen::box('', $lines, min(50, max(34, $cols - 4)), Theme::SELECTOR);

            return Screen::centerBlock($box, $cols, $rows);
        }

        if ($this->prFormOpen) {
            return $this->paintPrForm($cols, $rows);
        }

        if ($this->prEditOpen) {
            return $this->paintPrEdit($cols, $rows);
        }

        if ($this->prCommentOpen) {
            return $this->paintPrComment($cols, $rows);
        }

        if ($this->prDetailOpen && null !== ($pr = $this->currentDetailPull())) {
            return $this->paintPullDetail($pr, $cols, $rows);
        }

        $headerLines = $this->fuzzyOpen ? [Screen::fuzzySearchBar($this->fuzzyQuery, $cols)] : [];
        $bodyRows = max(1, $rows - \count($headerLines));

        if ($this->ctx->isPullsLoading()) {
            return array_merge($headerLines, Screen::spinnerBody($this->ctx->spinner, 'Loading pull requests...', $cols));
        }

        $pulls = $this->fuzzyOpen ? $this->fuzzyPulls() : $this->currentPulls();
        if ([] === $pulls) {
            $msg = $this->fuzzyOpen ? 'No matching pull requests.' : 'No items.';

            return array_merge($headerLines, $this->messageBody($msg, $cols));
        }

        $count = \count($pulls);
        $sel = min($this->fuzzyOpen ? $this->fuzzySel : $this->sel, $count - 1);
        $blocks = [];
        $blockStart = [];
        $lines = [];

        foreach ($pulls as $i => $pr) {
            $headRepo = $pr->headRepo();
            $headLabel = $headRepo->id() !== $pr->repo()->id()
                ? $headRepo->nameString() . ':' . $pr->headRef()
                : $pr->headRef();
            $bottom = match ($pr->status()) {
                PullRequestStatus::Merged => 'merged ' . $this->humanizeTime($pr->mergedAt() ?? $pr->updatedAt()),
                PullRequestStatus::Closed => 'closed ' . $this->humanizeTime($pr->closedAt() ?? $pr->updatedAt()),
                default => 'opened ' . $this->humanizeTime($pr->createdAt()),
            };
            $blocks[] = Screen::prItem(
                $pr->number(),
                $pr->status()->value,
                $pr->title(),
                $pr->baseRef(),
                $headLabel,
                $bottom,
                $i === $sel,
                $cols,
            );
        }

        /** @var array<int,int> absolute line → pull index */
        $lineBlock = [];
        foreach ($blocks as $bi => $block) {
            if ($bi > 0) {
                $lines[] = '';
            }
            $blockStart[$bi] = \count($lines);
            foreach ($block as $l) {
                $lineBlock[\count($lines)] = $bi;
                $lines[] = $l;
            }
        }

        $offset = 0;
        if (\count($lines) > $bodyRows) {
            $offset = max(0, min(($blockStart[$sel] ?? 0) - 1, \count($lines) - $bodyRows));
        }
        $view = \array_slice($lines, $offset, $bodyRows);
        while (\count($view) < $bodyRows) {
            $view[] = Screen::blank($cols);
        }

        $headerCount = \count($headerLines);
        foreach ($view as $r => $_) {
            $abs = $offset + $r;
            if (isset($lineBlock[$abs])) {
                $this->rowToIndex[$headerCount + $r] = $lineBlock[$abs];
            }
        }

        return array_merge($headerLines, $view);
    }

    /**
     * @return list<string>
     */
    private function paintPrForm(int $cols, int $rows): array
    {
        if ($this->ctx->isRefsLoading()) {
            return Screen::spinnerBody($this->ctx->spinner, 'Loading branches...', $cols);
        }
        $cardW = min(max(36, $cols - 2), 68);
        $innerW = max(24, $cardW - 6);

        $fullH = $rows + 1;
        $cardH = $fullH >= 26 ? $fullH - 2 : $fullH;
        $cardH = max(12, min($cardH, $rows));

        $headActive = 0 === $this->prFormField;
        $baseActive = 1 === $this->prFormField;
        $titleActive = 2 === $this->prFormField;
        $descActive = 3 === $this->prFormField;

        $helpText = match (true) {
            $headActive || $baseActive => 'Tab/↑/↓: field · Enter: select · Ctrl+S: submit · Esc: cancel',
            $descActive => 'Tab/Shift+Tab: field · Ctrl+S: submit · Ctrl+P: preview · Esc: cancel',
            default => 'Tab/↑/↓: field · Enter: next · Ctrl+S: submit · Esc: cancel',
        };
        $helpLines = $this->wrapPlainText($helpText, $innerW);

        $headerRows = [
            '',
            '   ' . Screen::serverBadge('New Pull Request'),
            '',
        ];
        if (null !== $this->prFormError && '' !== $this->prFormError) {
            $headerRows[] = '  ' . Theme::style('Error: ' . $this->prFormError, Theme::SELECTOR, bold: true);
            $headerRows[] = '';
        }

        $footerRows = [''];
        foreach ($helpLines as $hl) {
            $footerRows[] = '  ' . Theme::style($hl, Theme::MUTED);
        }
        $footerRows[] = '';

        $vpHeight = max(4, $cardH - 2 - \count($headerRows) - \count($footerRows));
        $descH = max(3, $vpHeight - 2);

        $vpLines = [];
        $bounds = [];

        // Field 0: Head
        $s0 = \count($vpLines);
        $vpLines[] = '  ' . Theme::style('Head (source branch or fork:branch):', Theme::BLUE, bold: true);
        $vpLines[] = '  ' . $this->renderBranchInputLine($this->prFormHead, 'feature-branch or repo:branch', $headActive, $this->headGhostActive, $this->headGhostIndex, $innerW);
        if ($headActive) {
            foreach ($this->renderBranchDropdown($this->prFormHead, $this->headGhostActive, $this->headGhostIndex, $innerW) as $dl) {
                $vpLines[] = '  ' . $dl;
            }
        }
        $vpLines[] = '';
        $bounds[0] = [$s0, \count($vpLines) - 1];

        // Field 1: Base
        $s1 = \count($vpLines);
        $vpLines[] = '  ' . Theme::style('Base (target branch):', Theme::BLUE, bold: true);
        $vpLines[] = '  ' . $this->renderBranchInputLine($this->prFormBase, 'main', $baseActive, $this->baseGhostActive, $this->baseGhostIndex, $innerW);
        if ($baseActive) {
            foreach ($this->renderBranchDropdown($this->prFormBase, $this->baseGhostActive, $this->baseGhostIndex, $innerW) as $dl) {
                $vpLines[] = '  ' . $dl;
            }
        }
        $vpLines[] = '';
        $bounds[1] = [$s1, \count($vpLines) - 1];

        // Field 2: Title
        $s2 = \count($vpLines);
        $vpLines[] = '  ' . Theme::style('Title:', Theme::BLUE, bold: true);
        $vpLines[] = '  ' . $this->renderTextInputLine($this->prFormTitle, 'Pull request title', $titleActive, $innerW);
        $vpLines[] = '';
        $bounds[2] = [$s2, \count($vpLines) - 1];

        // Field 3: Description
        $s3 = \count($vpLines);
        $vpLines[] = '  ' . Theme::style('Description:', Theme::BLUE, bold: true);
        $this->prFormDescEditor ??= new Editor();
        foreach ($this->prFormDescEditor->paintLines($innerW, $descH, $descActive) as $dl) {
            $vpLines[] = '  ' . $dl;
        }
        $bounds[3] = [$s3, \count($vpLines) - 1];

        $vpSlice = $this->sliceFormViewport($vpLines, $bounds, $this->prFormField, $vpHeight);

        $cardLines = array_merge($headerRows, $vpSlice, $footerRows);
        $box = Screen::box('', $cardLines, $cardW - 2, Theme::BLUE);

        return Screen::centerBlock($box, $cols, $rows);
    }

    private function renderBranchInputLine(string $value, string $placeholder, bool $focused, bool $ghostActive, int $ghostIdx, int $maxWidth = 0): string
    {
        $prompt = '> ';
        $suggestions = $this->branchSuggestions($value);

        if ('' === trim($value)) {
            if ($ghostActive && isset($suggestions[$ghostIdx])) {
                $target = $suggestions[$ghostIdx];
                if ($focused && '' !== $target) {
                    return $prompt . "\e[7m" . mb_substr($target, 0, 1) . "\e[0m" . Theme::style(mb_substr($target, 1), Theme::MUTED, faint: true);
                }

                return $prompt . Theme::style($target, Theme::MUTED, faint: true);
            }
            if ($focused) {
                return $prompt . "\e[7m" . mb_substr($placeholder, 0, 1) . "\e[0m" . Theme::style(mb_substr($placeholder, 1), Theme::MUTED, faint: true);
            }

            return $prompt . Theme::style($placeholder, Theme::MUTED, faint: true);
        }

        $ghostRemainder = '';
        if ($focused && [] !== $suggestions && \strlen($value) < \strlen($suggestions[0])) {
            $ghostRemainder = substr($suggestions[0], \strlen($value));
        }

        if ($focused) {
            if ('' !== $ghostRemainder) {
                return $prompt . $value . "\e[7m" . mb_substr($ghostRemainder, 0, 1) . "\e[0m" . Theme::style(mb_substr($ghostRemainder, 1), Theme::MUTED, faint: true);
            }

            $res = $prompt . $value . "\e[7m \e[0m";

            return $maxWidth > 4 ? Screen::truncate($res, $maxWidth) : $res;
        }

        $res = $prompt . $value;

        return $maxWidth > 4 ? Screen::truncate($res, $maxWidth) : $res;
    }

    /**
     * @return list<string>
     */
    private function renderBranchDropdown(string $value, bool $ghostActive, int $ghostIdx, int $width): array
    {
        $trimmed = trim($value);
        $items = $this->branchSuggestions($trimmed);
        if ([] === $items) {
            return [];
        }
        if ('' !== $trimmed && 1 === \count($items) && $items[0] === $trimmed) {
            return [];
        }
        $activeIdx = '' !== $trimmed ? 0 : ($ghostActive ? $ghostIdx : -1);

        $maxVisible = 3;
        $start = $activeIdx >= $maxVisible ? $activeIdx - $maxVisible + 1 : 0;
        $end = min(\count($items), $start + $maxVisible);

        $rows = [];
        for ($i = $start; $i < $end; ++$i) {
            $item = Screen::truncate($items[$i], max(8, $width - 6));
            if ($i === $activeIdx) {
                $rows[] = ' ' . Theme::style('▸ ' . $item, Theme::BLUE, bold: true);
            } else {
                $rows[] = ' ' . Theme::style('  ' . $item, Theme::MUTED);
            }
        }
        $remaining = \count($items) - $end;
        if ($remaining > 0) {
            $rows[] = ' ' . Theme::style(sprintf('  … %d more', $remaining), Theme::MUTED, faint: true);
        }

        return Screen::box('', $rows, max(18, $width - 2), Theme::MUTED);
    }

    private function renderTextInputLine(string $value, string $placeholder, bool $focused, int $maxWidth = 0): string
    {
        $prompt = '> ';
        if ('' === $value) {
            if ($focused) {
                return $prompt . "\e[7m" . mb_substr($placeholder, 0, 1) . "\e[0m" . Theme::style(mb_substr($placeholder, 1), Theme::MUTED, faint: true);
            }

            return $prompt . Theme::style($placeholder, Theme::MUTED, faint: true);
        }
        if ($focused) {
            $res = $prompt . $value . "\e[7m \e[0m";

            return $maxWidth > 4 ? Screen::truncate($res, $maxWidth) : $res;
        }

        $res = $prompt . $value;

        return $maxWidth > 4 ? Screen::truncate($res, $maxWidth) : $res;
    }

    /**
     * @param list<string>               $lines
     * @param array<int, array{int,int}> $bounds
     *
     * @return list<string>
     */
    private function sliceFormViewport(array $lines, array $bounds, int $focus, int $vpHeight): array
    {
        $focusTop = $bounds[$focus][0] ?? 0;
        $focusBottom = $bounds[$focus][1] ?? 0;
        $targetTop = $focus > 0 ? ($bounds[$focus - 1][0] ?? $focusTop) : $focusTop;
        $targetBottom = isset($bounds[$focus + 1]) ? $bounds[$focus + 1][1] : $focusBottom;

        $offset = 0;
        if ($targetBottom - $targetTop + 1 <= $vpHeight) {
            if ($targetBottom >= $vpHeight) {
                $offset = $targetBottom - $vpHeight + 1;
            }
        } else {
            $offset = max(0, min($focusTop, $targetBottom - $vpHeight + 1));
        }

        $slice = \array_slice($lines, $offset, $vpHeight);
        while (\count($slice) < $vpHeight) {
            $slice[] = '';
        }

        return $slice;
    }

    /**
     * @return list<string>
     */
    private function wrapPlainText(string $text, int $width): array
    {
        if ($width <= 10 || \strlen($text) <= $width) {
            return [$text];
        }
        $wrapped = wordwrap($text, $width, "\n", false);

        return explode("\n", $wrapped);
    }

    /**
     * @return list<string>
     */
    private function paintPrEdit(int $cols, int $rows): array
    {
        $pr = $this->currentDetailPull();
        $cardW = $cols - 4;
        $innerW = max(20, $cardW - 6);

        $fullH = $rows + 1;
        $cardH = $fullH >= 26 ? $fullH - 2 : $fullH;
        $cardH = max(12, min($cardH, $rows));

        $titleActive = 0 === $this->prEditField;
        $descActive = 1 === $this->prEditField;

        $helpText = 'tab switch field · Ctrl+S: save · Ctrl+P: preview · Esc: cancel';
        $helpLines = $this->wrapPlainText($helpText, $innerW);

        $badgeTitle = sprintf('Edit Pull Request #%d', $pr?->number() ?? $this->prDetailNumber ?? 0);
        $headerRows = [
            '',
            '   ' . Screen::serverBadge($badgeTitle),
            '',
        ];
        if (null !== $this->prEditError && '' !== $this->prEditError) {
            $headerRows[] = '  ' . Theme::style('Error: ' . $this->prEditError, Theme::SELECTOR, bold: true);
            $headerRows[] = '';
        }

        $footerRows = [''];
        foreach ($helpLines as $hl) {
            $footerRows[] = '  ' . Theme::style($hl, Theme::MUTED);
        }
        $footerRows[] = '';

        $vpHeight = max(4, $cardH - 2 - \count($headerRows) - \count($footerRows));
        $descH = max(3, min(8, $vpHeight - 2));

        $vpLines = [];
        $bounds = [];

        // Field 0: Title
        $s0 = \count($vpLines);
        $vpLines[] = '  ' . Theme::style('Title:', $titleActive ? Theme::BLUE : Theme::MUTED, bold: true);
        $vpLines[] = '  ' . $this->renderTextInputLine($this->prEditTitle, 'Pull request title', $titleActive, $innerW);
        $vpLines[] = '';
        $bounds[0] = [$s0, \count($vpLines) - 1];

        // Field 1: Description
        $s1 = \count($vpLines);
        $vpLines[] = '  ' . Theme::style('Description:', $descActive ? Theme::BLUE : Theme::MUTED, bold: true);
        $this->prEditDescEditor ??= new Editor();
        foreach ($this->prEditDescEditor->paintLines($innerW, $descH, $descActive) as $dl) {
            $vpLines[] = '  ' . $dl;
        }
        $vpLines[] = '';
        $bounds[1] = [$s1, \count($vpLines) - 1];

        $vpSlice = $this->sliceFormViewport($vpLines, $bounds, $this->prEditField, $vpHeight);

        $cardLines = array_merge($headerRows, $vpSlice, $footerRows);
        $box = Screen::box('', $cardLines, $cardW - 2, Theme::BLUE);

        return Screen::centerBlock($box, $cols, $rows);
    }

    /**
     * @return list<string>
     */
    private function paintPrComment(int $cols, int $rows): array
    {
        $title = $this->editingCommentId > 0 ? 'Edit Comment' : 'Add Comment';
        $header = ' ' . Screen::serverBadge($title);
        $hint = Theme::style('ctrl+s save • ctrl+p toggle preview • esc cancel', Theme::MUTED, italic: true);

        $this->prCommentEditor ??= new Editor();
        $editorRows = max(3, $rows - 3);
        $editorLines = $this->prCommentEditor->paintLines($cols, $editorRows, true);

        return array_merge([$header, ''], $editorLines, [$hint]);
    }

    /**
     * @return list<string>
     */
    private function paintPullDetail(PullRequest $pr, int $cols, int $rows): array
    {
        if (self::TAB_COMMITS === $this->detailTab && null !== $this->detailCommitsProcess) {
            return Screen::spinnerBody($this->ctx->spinner, 'Loading commits...', $cols);
        }
        if (self::TAB_CHANGES === $this->detailTab && null !== $this->detailDiffProcess) {
            return Screen::spinnerBody($this->ctx->spinner, 'Loading changes...', $cols);
        }
        if (self::TAB_ABOUT === $this->detailTab && $this->isDetailDescriptionLoading($pr, $cols)) {
            return Screen::spinnerBody($this->ctx->spinner, 'Loading pull request...', $cols);
        }
        if (self::TAB_COMMENTS === $this->detailTab && $this->isCommentsMarkdownLoading($cols)) {
            return Screen::spinnerBody($this->ctx->spinner, 'Loading comments...', $cols);
        }

        $lines = match ($this->detailTab) {
            self::TAB_COMMITS => $this->renderCommitsTabLines($cols),
            self::TAB_CHANGES => $this->renderChangesTabLines($cols),
            self::TAB_COMMENTS => $this->renderCommentsTabLines($cols),
            default => $this->renderAboutTabLines($pr, $cols),
        };

        return $this->docWindow($lines, $cols, $rows);
    }

    private function isDetailDescriptionLoading(PullRequest $pr, int $cols): bool
    {
        if ($this->isDetailLoading()) {
            return true;
        }
        $desc = trim($pr->description());
        if ('' === $desc || !$this->ctx->renderer->isAvailable()) {
            return false;
        }
        $width = max(20, $cols - 4);
        $key = $pr->number() . ':' . $width . ':' . md5($desc);
        if (null !== $this->detailDescCache && $this->detailDescCache['key'] === $key) {
            return false;
        }
        if (null === $this->detailDescJob || $this->detailDescJob['key'] !== $key) {
            if (null !== $this->detailDescJob && $this->detailDescJob['process']->isRunning()) {
                $this->detailDescJob['process']->stop(0);
            }
            $fallback = preg_split('/\r\n|\r|\n/', $desc) ?: [$desc];
            try {
                $this->detailDescJob = [
                    'key' => $key,
                    'process' => $this->ctx->renderer->startRenderDocumentProcess('description.md', $desc, $width),
                    'fallback' => $fallback,
                ];
            } catch (\Throwable) {
                $this->detailDescCache = ['key' => $key, 'lines' => $fallback];

                return false;
            }
        }

        return true;
    }

    private function isCommentsMarkdownLoading(int $cols): bool
    {
        if ([] === $this->detailComments || !$this->ctx->renderer->isAvailable()) {
            return false;
        }
        $width = max(20, $cols - 6);
        foreach ($this->detailComments as $c) {
            $body = trim($c->body());
            if ('' === $body) {
                continue;
            }
            $key = $width . ':' . md5($body);
            if (isset($this->commentRenderCache[$key])) {
                continue;
            }
            if (null === $this->commentRenderJob || $this->commentRenderJob['key'] !== $key) {
                if (null !== $this->commentRenderJob && $this->commentRenderJob['process']->isRunning()) {
                    $this->commentRenderJob['process']->stop(0);
                }
                $fallback = preg_split('/\r\n|\r|\n/', $body) ?: [$body];
                try {
                    $this->commentRenderJob = [
                        'key' => $key,
                        'process' => $this->ctx->renderer->startRenderDocumentProcess('comment.md', $body, $width),
                        'fallback' => $fallback,
                    ];
                } catch (\Throwable) {
                    $this->commentRenderCache[$key] = $fallback;

                    continue;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function renderAboutTabLines(PullRequest $pr, int $cols): array
    {
        $lines = [];

        // 1. Header: #num Title
        $lines[] = '  ' . Theme::style(sprintf('#%d %s', $pr->number(), $pr->title()), Theme::BLUE, bold: true);
        $lines[] = '';

        // 2. Status Badge & Branches
        $badgeColor = match ($pr->status()) {
            PullRequestStatus::Open => Theme::GREEN,
            PullRequestStatus::Merged => Theme::HIGHLIGHT,
            PullRequestStatus::Closed => Theme::SELECTOR,
        };
        $statusBadge = Theme::style('[' . strtoupper($pr->status()->value) . ']', $badgeColor, bold: true);
        $headRepo = $pr->headRepo();
        $headLabel = $headRepo->id() !== $pr->repo()->id()
            ? $headRepo->nameString() . ':' . $pr->headRef()
            : $pr->headRef();
        $lines[] = sprintf(
            '  %s  %s wants to merge into %s from %s',
            $statusBadge,
            Theme::style($this->humanizeTime($pr->createdAt()), Theme::FG_DIM),
            Theme::style($pr->baseRef(), Theme::HASH),
            Theme::style($headLabel, Theme::HASH),
        );

        // 3. Mergeability indicator
        if ($pr->isOpen()) {
            if ($this->detailMergeable) {
                $lines[] = '  ' . Theme::style("✓ Able to merge cleanly (press 'm' to merge)", Theme::GREEN);
            } else {
                $lines[] = '  ' . Theme::style('✗ Cannot be merged automatically (conflicts detected)', Theme::SELECTOR);
            }
        } elseif (PullRequestStatus::Merged === $pr->status() && null !== $pr->mergeCommit() && '' !== $pr->mergeCommit()) {
            $lines[] = '  ' . Theme::style(
                sprintf('✓ Merged into %s (commit %s)', $pr->baseRef(), substr($pr->mergeCommit(), 0, 7)),
                Theme::HIGHLIGHT,
            );
        }

        // 4. Summary metadata (Commits count, Files changed, Comments count, Dates)
        $filesCount = null !== $this->detailDiff ? \count($this->detailDiff->files) : 0;
        $lines[] = '';
        $lines[] = '  ' . Theme::style(sprintf(
            'Commits: %d  •  Files changed: %d  •  Comments: %d  •  Updated: %s',
            \count($this->detailCommits),
            $filesCount,
            \count($this->detailComments),
            $this->humanizeTime($pr->updatedAt()),
        ), Theme::MUTED);

        // 5. Description (rendered as Markdown via Termstrap)
        $desc = trim($pr->description());
        $lines[] = '';
        if ('' !== $desc) {
            $lines[] = '  ' . Theme::style('Description:', null, bold: true);
            $descLines = $this->detailDescCache['lines'] ?? (preg_split('/\r\n|\r|\n/', $desc) ?: [$desc]);
            foreach ($descLines as $dl) {
                $lines[] = '  ' . $dl;
            }
        } else {
            $lines[] = '  ' . Theme::style('No description provided.', Theme::MUTED, italic: true);
        }

        if ([] !== $this->detailComments) {
            $lines[] = '';
            $lines[] = '  ' . Theme::style(sprintf('Comments (%d):', \count($this->detailComments)), null, bold: true);
            foreach ($this->detailComments as $c) {
                $lines[] = sprintf(
                    '    %s  %s: %s',
                    Theme::style($c->author()->username(), Theme::BLUE, bold: true),
                    Theme::style($this->humanizeTime($c->createdAt()), Theme::MUTED),
                    $c->body(),
                );
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function renderCommitsTabLines(int $cols): array
    {
        $lines = [
            '  ' . Theme::style(sprintf('Commits (%d):', \count($this->detailCommits)), null, bold: true),
        ];

        if ([] === $this->detailCommits) {
            $lines[] = '';
            $lines[] = '  ' . Theme::style('No commits found.', Theme::MUTED, italic: true);

            return $lines;
        }

        $lines[] = '  ' . Theme::style('↑/↓ or j/k or click to select • Enter to view commit • wheel to scroll', Theme::MUTED, italic: true);
        $lines[] = '';

        $offsets = [];
        foreach ($this->detailCommits as $i => $c) {
            $offsets[] = \count($lines);
            $isSelected = $i === $this->selectedCommitIdx;
            $cursor = $isSelected ? Theme::style('▸ ', Theme::BLUE, bold: true) : '  ';
            $hash = $isSelected
                ? Theme::style($c->shortHash, Theme::HIGHLIGHT, bold: true)
                : Theme::style($c->shortHash, Theme::HASH);
            $summary = $isSelected
                ? Theme::style($c->subject, Theme::BLUE, bold: true)
                : $c->subject;
            $when = $this->relativeTime($c->timestamp);
            $meta = Theme::style(sprintf('(%s, %s)', $c->authorName, $when), Theme::MUTED);
            $lines[] = Screen::fit('  ' . $cursor . $hash . ' ' . $summary . ' ' . $meta, $cols);
        }
        $this->commitLineOffsets = $offsets;

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function renderChangesTabLines(int $cols): array
    {
        if (null === $this->detailDiff || [] === $this->detailDiff->files) {
            return [
                '  ' . Theme::style('Changes (0):', null, bold: true),
                '',
                '  ' . Theme::style('No file changes.', Theme::MUTED, italic: true),
            ];
        }

        $files = $this->detailDiff->files;
        $lines = [
            '  ' . Theme::style(sprintf('Files changed (%d):', \count($files)), null, bold: true),
            '  ' . Theme::style('↑/↓ or j/k scroll & focus file • Enter/Space to toggle diff • a toggle all', Theme::MUTED, italic: true),
            '',
        ];

        $offsets = [];
        foreach ($files as $i => $f) {
            $offsets[] = \count($lines);
            $isSelected = $i === $this->selectedFileIdx;
            $isExpanded = $this->expandedFiles[$i] ?? false;
            $cursor = $isSelected ? Theme::style('▸ ', Theme::BLUE, bold: true) : '  ';
            $foldIcon = $isExpanded ? '▼' : '▶';
            $name = $this->diffFileName($f);
            $nameRendered = $isSelected ? Theme::style($name, Theme::BLUE, bold: true) : $name;
            $addStr = Theme::style('+' . $f->additions, Theme::GREEN);
            $delStr = Theme::style('-' . $f->deletions, Theme::SELECTOR);
            $lines[] = sprintf('  %s%s %s  (%s %s)', $cursor, $foldIcon, $nameRendered, $addStr, $delStr);

            if ($isExpanded) {
                $patchLines = preg_split('/\r\n|\r|\n/', rtrim($f->patch, "\n")) ?: [];
                foreach ($this->colourDiff($patchLines) as $pl) {
                    $lines[] = '    ' . $pl;
                }
                $lines[] = '';
            }
        }
        $this->fileLineOffsets = $offsets;
        $this->diffTotalLines = \count($lines);

        return $lines;
    }

    private function diffFileName(DiffFile $f): string
    {
        if ('' !== $f->oldPath && '' !== $f->newPath && $f->oldPath !== $f->newPath) {
            return sprintf('%s → %s', $f->oldPath, $f->newPath);
        }

        return '' !== $f->newPath ? $f->newPath : $f->oldPath;
    }

    /**
     * @return list<string>
     */
    private function renderCommentsTabLines(int $cols): array
    {
        $lines = [
            '  ' . Theme::style(sprintf('Comments (%d):', \count($this->detailComments)), null, bold: true),
            '  ' . Theme::style("Press 'c' for the comment menu", Theme::MUTED, italic: true),
            '',
        ];

        if ([] === $this->detailComments) {
            $lines[] = '  ' . Theme::style('No comments yet.', Theme::MUTED, italic: true);

            return $lines;
        }

        foreach ($this->detailComments as $c) {
            $author = Theme::style($c->author()->username(), Theme::BLUE, bold: true);
            $time = Theme::style($this->humanizeTime($c->createdAt()), Theme::MUTED);
            $lines[] = '  ' . $author . '  ' . $time;

            $body = trim($c->body());
            $key = max(20, $cols - 6) . ':' . md5($body);
            $bodyLines = $this->commentRenderCache[$key] ?? (preg_split('/\r\n|\r|\n/', $body) ?: [$body]);
            foreach ($bodyLines as $bl) {
                $lines[] = '    ' . $bl;
            }
            $lines[] = '';
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function renderPrCommitDetailLines(int $cols): array
    {
        $c = $this->viewingCommit;
        if (null === $c) {
            return [];
        }

        $lines = [
            '  ' . Theme::style('commit ' . $c->hash, Theme::HASH, bold: true),
            '  ' . Theme::style(sprintf('Author: %s <%s>', $c->authorName, $c->authorEmail), Theme::FG_DIM),
            '  ' . Theme::style('Date:   ' . date('D M j H:i:s Y', $c->timestamp), Theme::MUTED),
            '',
            '  ' . $c->subject,
        ];
        if ('' !== trim($c->body)) {
            $lines[] = '';
            foreach (preg_split('/\r\n|\r|\n/', rtrim($c->body, "\n")) ?: [] as $bl) {
                $lines[] = '  ' . $bl;
            }
        }
        $lines[] = '';

        if (null !== $this->viewingCommitDiff) {
            foreach ($this->viewingCommitDiff->files as $f) {
                $add = Theme::style('+' . $f->additions, Theme::GREEN);
                $del = Theme::style('-' . $f->deletions, Theme::SELECTOR);
                $lines[] = sprintf('  %s | %s %s', $this->diffFileName($f), $add, $del);
            }
            $lines[] = '';
            $rawLines = preg_split('/\r\n|\r|\n/', rtrim($this->viewingCommitDiff->rawDiff, "\n")) ?: [];
            foreach ($this->colourDiff($rawLines) as $dl) {
                $lines[] = '  ' . $dl;
            }
        }

        return $lines;
    }

    private function scrollToSelectedCommit(): void
    {
        $top = $this->commitLineOffsets[$this->selectedCommitIdx] ?? null;
        if (null === $top) {
            return;
        }
        $h = max(1, $this->rows);
        if ($top < $this->scroll || $top >= $this->scroll + $h) {
            $this->scroll = max(0, $top);
        }
    }

    private function moveDiffFocus(bool $down): void
    {
        if (null === $this->detailDiff) {
            return;
        }
        $n = \count($this->detailDiff->files);
        if (0 === $n || \count($this->fileLineOffsets) !== $n) {
            return;
        }
        $h = max(1, $this->rows);
        $off = $this->scroll;
        $cur = max(0, min($this->selectedFileIdx, $n - 1));
        $top = $this->fileLineOffsets[$cur];

        if ($down) {
            $end = ($cur + 1 < $n) ? ($this->fileLineOffsets[$cur + 1] - 1) : ($this->diffTotalLines - 1);
            if ($off + $h - 1 < $end) {
                ++$off;
            } elseif ($cur < $n - 1) {
                ++$cur;
                $off = $this->fileLineOffsets[$cur];
            }
        } else {
            if ($off > $top) {
                --$off;
            } elseif ($cur > 0) {
                --$cur;
                $off = max($this->fileLineOffsets[$cur], $this->fileLineOffsets[$cur + 1] - $h);
            }
        }

        $this->selectedFileIdx = $cur;
        $this->scroll = max(0, $off);
    }

    private function humanizeTime(\DateTimeImmutable $dt): string
    {
        $diff = max(0, time() - $dt->getTimestamp());
        if ($diff < 45) {
            return 'just now';
        }
        if ($diff < 3600) {
            $m = max(1, intdiv($diff, 60));

            return $m . (1 === $m ? ' minute ago' : ' minutes ago');
        }
        if ($diff < 86400) {
            $h = max(1, intdiv($diff, 3600));

            return $h . (1 === $h ? ' hour ago' : ' hours ago');
        }
        $d = max(1, intdiv($diff, 86400));

        return $d . (1 === $d ? ' day ago' : ' days ago');
    }
}

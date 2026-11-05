<?php

declare(strict_types=1);

namespace App\UI\Tui\Page\Repo;

use App\PullRequest\Domain\Model\PullRequest;
use App\PullRequest\Domain\PullRequestStatus;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Domain\Exception\NotFound;
use App\UI\Tui\Input\Keys;
use App\UI\Tui\Render\Screen;
use Symfony\Component\Process\Process;
use Symfony\Component\Tui\Input\KeyParser;

/**
 * Pull Requests tab: a list of PRs with a detail view, diff view, merge,
 * close/reopen, edit, comment, delete, and new PR creation backed by the
 * PullRequest application use cases.
 * Read-only (list only) when the repository is not registered on this server.
 */
final class PullsTab extends AbstractRepoTab
{
    private bool $prDetailOpen = false;
    private ?int $prDetailNumber = null;
    private bool $prMergeConfirm = false;
    private bool $prDeleteConfirm = false;
    private bool $prDiffOpen = false;
    private ?Process $prDiffProcess = null;
    /** @var list<string> */
    private array $prDiffLines = [];
    private int $prDiffScroll = 0;

    // Filter
    private ?PullRequestStatus $filterStatus = PullRequestStatus::Open;

    // New PR Form
    private bool $prFormOpen = false;
    private int $prFormField = 0; // 0=head, 1=base, 2=title, 3=desc
    private string $prFormHead = '';
    private string $prFormBase = '';
    private string $prFormTitle = '';
    private string $prFormDesc = '';

    // Edit PR Form
    private bool $prEditOpen = false;
    private int $prEditField = 0; // 0=title, 1=desc
    private string $prEditTitle = '';
    private string $prEditDesc = '';

    // Comment Form
    private bool $prCommentOpen = false;
    private string $prCommentBody = '';

    private ?KeyParser $keyParser = null;

    public function capturesInput(): bool
    {
        return $this->prDiffOpen
            || $this->prMergeConfirm
            || $this->prDeleteConfirm
            || $this->prFormOpen
            || $this->prEditOpen
            || $this->prCommentOpen
            || $this->prDetailOpen;
    }

    public function onActivate(): void
    {
        parent::onActivate();
        $this->prDetailOpen = false;
        $this->prDetailNumber = null;
        $this->prMergeConfirm = false;
        $this->prDeleteConfirm = false;
        $this->prDiffOpen = false;
        if (null !== $this->prDiffProcess && $this->prDiffProcess->isRunning()) {
            $this->prDiffProcess->stop(0);
        }
        $this->prDiffProcess = null;
        $this->prFormOpen = false;
        $this->prEditOpen = false;
        $this->prCommentOpen = false;
    }

    public function tick(?float $deltaTime = null): bool
    {
        if (null === $this->prDiffProcess) {
            return false;
        }

        $this->ctx->spinner->advance($deltaTime);
        if ($this->prDiffProcess->isRunning()) {
            return true;
        }

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

        return true;
    }

    public function footerText(): string
    {
        if ($this->prDiffOpen) {
            return ' ↑/↓ scroll · Esc: back';
        }
        if ($this->prMergeConfirm) {
            return ' y: confirm merge · n/Esc: cancel';
        }
        if ($this->prDeleteConfirm) {
            return ' y: confirm delete · n/Esc: cancel';
        }
        if ($this->prFormOpen) {
            return ' Tab/↑/↓: field · Space/←/→: cycle branch · Ctrl+S: create · Esc: cancel';
        }
        if ($this->prEditOpen) {
            return ' Tab/↑/↓: field · Ctrl+S: save · Esc: cancel';
        }
        if ($this->prCommentOpen) {
            return ' Enter: newline · Ctrl+S: post · Esc: cancel';
        }
        if ($this->prDetailOpen) {
            $pr = $this->currentDetailPull();
            $statusAction = ($pr?->isOpen() ?? true) ? 's: close' : 's: reopen';
            $mergeAction = ($pr?->isOpen() ?? true) ? 'm: merge · ' : '';

            return ' '.$mergeAction.$statusAction.' · e: edit · c: comment · d: diff · x: delete · Esc: back';
        }

        return ' ↑/↓ move · Enter: view · n: new · f: filter · m: merge · Tab: next · q: back';
    }

    public function statusValue(): string
    {
        $filter = match ($this->filterStatus) {
            PullRequestStatus::Open => 'open',
            PullRequestStatus::Closed => 'closed',
            PullRequestStatus::Merged => 'merged',
            null => 'all',
        };

        return 'filter: '.$filter;
    }

    public function statusInfo(): string
    {
        if ($this->prDetailOpen && null !== ($pr = $this->currentDetailPull())) {
            return sprintf('%s → %s', $pr->headRef(), $pr->baseRef());
        }

        return '';
    }

    public function handleKey(string $seq): bool
    {
        if ($this->prDiffOpen) {
            return $this->handlePrDiffKey($seq);
        }
        if ($this->prMergeConfirm) {
            return $this->handlePrMergeConfirmKey($seq);
        }
        if ($this->prDeleteConfirm) {
            return $this->handlePrDeleteConfirmKey($seq);
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

        return $this->handlePullsTabKey(Keys::normalize($seq), Keys::text($seq));
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
                $this->prDetailOpen = true;
                $this->prDetailNumber = $pr->number();

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
                PullRequestStatus::Open => PullRequestStatus::Closed,
                PullRequestStatus::Closed => PullRequestStatus::Merged,
                PullRequestStatus::Merged => null,
                null => PullRequestStatus::Open,
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

    private function openNewPrForm(): void
    {
        $this->prFormOpen = true;
        $this->prFormField = 0;
        $branches = $this->ctx->branchList();
        $defaultBase = $this->ctx->git->defaultBranch;
        $this->prFormBase = \in_array($defaultBase, $branches, true) ? $defaultBase : ($branches[0] ?? 'main');

        $candidateHead = null;
        if ($this->ctx->currentRefIsBranch() && $this->ctx->ref !== $this->prFormBase) {
            $candidateHead = $this->ctx->ref;
        } else {
            foreach ($branches as $b) {
                if ($b !== $this->prFormBase) {
                    $candidateHead = $b;
                    break;
                }
            }
        }
        $this->prFormHead = $candidateHead ?? ($branches[0] ?? $defaultBase);
        $this->prFormTitle = '';
        $this->prFormDesc = '';
    }

    private function handlePrFormKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if (Keys::ESCAPE === $tok) {
            $this->prFormOpen = false;

            return true;
        }

        if ($this->isCtrlS($seq)) {
            $this->submitNewPr();

            return true;
        }

        if (Keys::TAB === $tok || Keys::DOWN === $tok) {
            $this->prFormField = ($this->prFormField + 1) % 4;

            return true;
        }

        if (Keys::BACKTAB === $tok || Keys::UP === $tok) {
            $this->prFormField = ($this->prFormField + 3) % 4;

            return true;
        }

        if (0 === $this->prFormField || 1 === $this->prFormField) {
            if (Keys::ENTER === $tok) {
                $this->prFormField = ($this->prFormField + 1) % 4;

                return true;
            }
            if (Keys::SPACE === $tok || Keys::RIGHT === $tok) {
                $this->cycleBranch($this->prFormField, 1);

                return true;
            }
            if (Keys::LEFT === $tok) {
                $this->cycleBranch($this->prFormField, -1);

                return true;
            }
            if (null !== $txt && '' !== $txt) {
                $branches = $this->ctx->branchList();
                foreach ($branches as $b) {
                    if (str_starts_with(strtolower($b), strtolower($txt))) {
                        0 === $this->prFormField ? ($this->prFormHead = $b) : ($this->prFormBase = $b);
                        break;
                    }
                }

                return true;
            }

            return true;
        }

        if (2 === $this->prFormField) {
            if (Keys::ENTER === $tok) {
                $this->prFormField = 3;

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
            if (Keys::ENTER === $tok) {
                $this->prFormDesc .= "\n";

                return true;
            }
            if (Keys::BACKSPACE === $tok) {
                $this->prFormDesc = mb_substr($this->prFormDesc, 0, -1);

                return true;
            }
            if (null !== $txt) {
                $this->prFormDesc .= $txt;

                return true;
            }

            return true;
        }

        return true;
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
            $this->ctx->flash = 'PR creation is unavailable.';

            return;
        }

        $title = trim($this->prFormTitle);
        if ('' === $title) {
            $this->ctx->flash = 'Pull request title cannot be empty.';

            return;
        }

        $head = trim($this->prFormHead);
        if ('' === $head) {
            $this->ctx->flash = 'Head branch cannot be empty.';

            return;
        }

        $base = trim($this->prFormBase);

        try {
            $pr = ($this->ctx->openPullRequest)(
                $this->ctx->actor,
                $this->ctx->db->name(),
                $title,
                $this->prFormDesc,
                $head,
                '' !== $base ? $base : null,
            );
            $this->ctx->flash = sprintf('Opened PR #%d', $pr->number());
            $this->ctx->pullsCache = null;
            $this->prFormOpen = false;
            $this->filterStatus = PullRequestStatus::Open;
            $this->sel = 0;
        } catch (AccessDenied|NotFound|Conflict|InvalidInput|\RuntimeException $e) {
            $this->ctx->flash = $e->getMessage();
        }
    }

    private function handlePrDetailKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if (Keys::ESCAPE === $tok || 'q' === $txt) {
            $this->prDetailOpen = false;
            $this->prDetailNumber = null;

            return true;
        }

        $pr = $this->currentDetailPull();
        if (null === $pr) {
            $this->prDetailOpen = false;
            $this->prDetailNumber = null;

            return true;
        }

        if ('m' === $txt && $pr->isOpen() && null !== $this->ctx->mergePullRequest) {
            $this->prMergeConfirm = true;

            return true;
        }

        if ('d' === $txt && null !== $this->ctx->pullRequestQuery && null !== $this->ctx->db) {
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
                $this->ctx->flash = 'Failed to load diff: '.$e->getMessage();
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
                } catch (AccessDenied|NotFound|Conflict|InvalidInput|\RuntimeException $e) {
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
                } catch (AccessDenied|NotFound|Conflict|InvalidInput|\RuntimeException $e) {
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
            $this->prEditTitle = $pr->title();
            $this->prEditDesc = $pr->description();

            return true;
        }

        if ('c' === $txt && null !== $this->ctx->commentPullRequest && null !== $this->ctx->db) {
            if (null === $this->ctx->actor) {
                $this->ctx->flash = 'Authentication required.';

                return true;
            }
            $this->prCommentOpen = true;
            $this->prCommentBody = '';

            return true;
        }

        if ('x' === $txt && null !== $this->ctx->deletePullRequest && null !== $this->ctx->db) {
            if (null === $this->ctx->actor) {
                $this->ctx->flash = 'Authentication required.';

                return true;
            }
            $this->prDeleteConfirm = true;

            return true;
        }

        return false;
    }

    private function handlePrEditKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if (Keys::ESCAPE === $tok) {
            $this->prEditOpen = false;

            return true;
        }

        if ($this->isCtrlS($seq)) {
            $this->submitEditPr();

            return true;
        }

        if (Keys::TAB === $tok || Keys::DOWN === $tok || Keys::BACKTAB === $tok || Keys::UP === $tok) {
            $this->prEditField = ($this->prEditField + 1) % 2;

            return true;
        }

        if (0 === $this->prEditField) {
            if (Keys::ENTER === $tok) {
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
            if (Keys::ENTER === $tok) {
                $this->prEditDesc .= "\n";

                return true;
            }
            if (Keys::BACKSPACE === $tok) {
                $this->prEditDesc = mb_substr($this->prEditDesc, 0, -1);

                return true;
            }
            if (null !== $txt) {
                $this->prEditDesc .= $txt;

                return true;
            }

            return true;
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
            $this->ctx->flash = 'Pull request title cannot be empty.';

            return;
        }

        try {
            ($this->ctx->updatePullRequest)(
                $this->ctx->actor,
                $this->ctx->db->name(),
                $pr->number(),
                $title,
                $this->prEditDesc,
            );
            $this->ctx->flash = sprintf('Updated PR #%d', $pr->number());
            $this->ctx->pullsCache = null;
            $this->prEditOpen = false;
        } catch (AccessDenied|NotFound|Conflict|InvalidInput|\RuntimeException $e) {
            $this->ctx->flash = $e->getMessage();
        }
    }

    private function handlePrCommentKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if (Keys::ESCAPE === $tok) {
            $this->prCommentOpen = false;

            return true;
        }

        if ($this->isCtrlS($seq)) {
            $this->submitComment();

            return true;
        }

        if (Keys::ENTER === $tok) {
            $this->prCommentBody .= "\n";

            return true;
        }

        if (Keys::BACKSPACE === $tok) {
            $this->prCommentBody = mb_substr($this->prCommentBody, 0, -1);

            return true;
        }

        if (null !== $txt) {
            $this->prCommentBody .= $txt;

            return true;
        }

        return true;
    }

    private function submitComment(): void
    {
        $pr = $this->currentDetailPull();
        if (null === $pr || null === $this->ctx->commentPullRequest || null === $this->ctx->db || null === $this->ctx->actor) {
            $this->ctx->flash = 'Comment unavailable.';

            return;
        }

        $body = trim($this->prCommentBody);
        if ('' === $body) {
            $this->ctx->flash = 'Comment body cannot be empty.';

            return;
        }

        try {
            ($this->ctx->commentPullRequest)(
                $this->ctx->actor,
                $this->ctx->db->name(),
                $pr->number(),
                $this->prCommentBody,
            );
            $this->ctx->flash = 'Comment added.';
            $this->ctx->pullsCache = null;
            $this->prCommentOpen = false;
        } catch (AccessDenied|NotFound|Conflict|InvalidInput|\RuntimeException $e) {
            $this->ctx->flash = $e->getMessage();
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
            $this->ctx->flash = 'Merge failed: '.$e->getMessage();
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
        } catch (AccessDenied|NotFound|Conflict|InvalidInput|\RuntimeException $e) {
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

        return array_values(array_filter($pulls, fn (PullRequest $pr): bool => $pr->status() === $this->filterStatus));
    }

    private function selectedPull(): ?PullRequest
    {
        $pulls = $this->currentPulls();

        return $pulls[min($this->sel, max(0, \count($pulls) - 1))] ?? null;
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

    public function paintBody(int $cols, int $rows): array
    {
        if (null === $this->ctx->db || null === $this->ctx->pullRequestQuery) {
            return $this->messageBody('Pull requests are unavailable for local repositories.', $cols);
        }

        if ($this->prDiffOpen) {
            $header = "\e[2m  Diff for PR #".($this->currentDetailPull()?->number() ?? 0)."\e[0m";
            if (null !== $this->prDiffProcess) {
                return array_merge([$header], Screen::spinnerBody($this->ctx->spinner, 'Loading diff...', $cols));
            }

            return array_merge([$header], $this->docWindow($this->colourDiff($this->prDiffLines), $cols, max(1, $rows - 1)));
        }

        if ($this->prMergeConfirm) {
            $pr = $this->currentDetailPull() ?? $this->selectedPull();
            $lines = [
                '',
                sprintf('  Merge pull request #%d: %s?', $pr?->number() ?? 0, $pr?->title() ?? ''),
                sprintf('  into base branch "%s"', $pr?->baseRef() ?? ''),
                '',
                '  Press y to confirm merge, or n/Esc to cancel.',
            ];
            $box = Screen::box('Confirm merge', $lines, min(60, max(30, $cols - 4)));

            return Screen::centerBlock($box, $cols, $rows);
        }

        if ($this->prDeleteConfirm) {
            $pr = $this->currentDetailPull();
            $lines = [
                '',
                sprintf('  Permanently delete pull request #%d: %s?', $pr?->number() ?? 0, $pr?->title() ?? ''),
                '',
                '  Press y to confirm delete, or n/Esc to cancel.',
            ];
            $box = Screen::box('Confirm delete', $lines, min(60, max(30, $cols - 4)));

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

        if ($this->ctx->isPullsLoading()) {
            return Screen::spinnerBody($this->ctx->spinner, 'Loading pull requests...', $cols);
        }

        $pulls = $this->currentPulls();
        if ([] === $pulls) {
            return $this->messageBody('No items.', $cols);
        }

        $items = [];
        foreach ($pulls as $pr) {
            $statusBadge = match ($pr->status()) {
                PullRequestStatus::Open => "\e[32mopen\e[0m",
                PullRequestStatus::Merged => "\e[35mmerged\e[0m",
                PullRequestStatus::Closed => "\e[31mclosed\e[0m",
            };
            $label = sprintf('#%d  %s', $pr->number(), $pr->title());
            $meta = sprintf('%s  %s → %s', $statusBadge, $pr->headRef(), $pr->baseRef());
            $items[] = ['label' => $label, 'meta' => $meta];
        }

        return Screen::listView($items, $this->sel, $rows, $cols);
    }

    /**
     * @return list<string>
     */
    private function paintPrForm(int $cols, int $rows): array
    {
        $headActive = 0 === $this->prFormField;
        $baseActive = 1 === $this->prFormField;
        $titleActive = 2 === $this->prFormField;
        $descActive = 3 === $this->prFormField;

        $lines = [
            '',
            ($headActive ? ' > ' : '   ').'Head branch : '.$this->prFormHead.($headActive ? '  (←/→/Space: cycle)' : ''),
            ($baseActive ? ' > ' : '   ').'Base branch : '.$this->prFormBase.($baseActive ? '  (←/→/Space: cycle)' : ''),
            ($titleActive ? ' > ' : '   ').'Title       : '.$this->prFormTitle.($titleActive ? '▌' : ''),
            ($descActive ? ' > ' : '   ').'Description : '.('' !== $this->prFormDesc ? str_replace("\n", ' ↵ ', $this->prFormDesc) : '(empty)').($descActive ? '▌' : ''),
            '',
            '  Tab/↑/↓: next · Space/←/→: cycle · Ctrl+S: create · Esc: cancel',
        ];
        $box = Screen::box('New Pull Request', $lines, min(70, max(45, $cols - 4)));

        return Screen::centerBlock($box, $cols, $rows);
    }

    /**
     * @return list<string>
     */
    private function paintPrEdit(int $cols, int $rows): array
    {
        $pr = $this->currentDetailPull();
        $titleActive = 0 === $this->prEditField;
        $descActive = 1 === $this->prEditField;

        $lines = [
            '',
            ($titleActive ? ' > ' : '   ').'Title       : '.$this->prEditTitle.($titleActive ? '▌' : ''),
            ($descActive ? ' > ' : '   ').'Description : '.('' !== $this->prEditDesc ? str_replace("\n", ' ↵ ', $this->prEditDesc) : '(empty)').($descActive ? '▌' : ''),
            '',
            '  Tab/↑/↓: next · Ctrl+S: save · Esc: cancel',
        ];
        $box = Screen::box(sprintf('Edit Pull Request #%d', $pr?->number() ?? 0), $lines, min(70, max(45, $cols - 4)));

        return Screen::centerBlock($box, $cols, $rows);
    }

    /**
     * @return list<string>
     */
    private function paintPrComment(int $cols, int $rows): array
    {
        $pr = $this->currentDetailPull();
        $lines = [
            '',
            '  Enter comment body:',
            '',
        ];
        $bodyLines = explode("\n", $this->prCommentBody);
        foreach ($bodyLines as $i => $bl) {
            $cursor = ($i === \count($bodyLines) - 1) ? '▌' : '';
            $lines[] = '    '.$bl.$cursor;
        }
        $lines[] = '';
        $lines[] = '  Ctrl+S: post comment · Enter: newline · Esc: cancel';
        $box = Screen::box(sprintf('Add Comment on PR #%d', $pr?->number() ?? 0), $lines, min(70, max(45, $cols - 4)));

        return Screen::centerBlock($box, $cols, $rows);
    }

    /**
     * @return list<string>
     */
    private function paintPullDetail(PullRequest $pr, int $cols, int $rows): array
    {
        $statusStr = match ($pr->status()) {
            PullRequestStatus::Open => "\e[32mopen\e[0m",
            PullRequestStatus::Merged => "\e[35mmerged\e[0m",
            PullRequestStatus::Closed => "\e[31mclosed\e[0m",
        };
        $lines = [
            sprintf('  #%d: %s [%s]', $pr->number(), $pr->title(), $statusStr),
            sprintf('  Author: %s · Base: %s · Head: %s', $pr->author()->username(), $pr->baseRef(), $pr->headRef()),
            '',
            '  '.('' !== $pr->description() ? $pr->description() : 'No description.'),
            '',
        ];

        $comments = $this->ctx->pullRequestQuery?->comments($this->ctx->actor, $this->ctx->db?->name() ?? '', $pr->number()) ?? [];
        if ([] !== $comments) {
            $lines[] = '  Comments:';
            foreach ($comments as $c) {
                $lines[] = sprintf('    %s: %s', $c->author()->username(), $c->body());
            }
            $lines[] = '';
        }

        $statusAction = $pr->isOpen() ? 's: close' : 's: reopen';
        $mergeAction = $pr->isOpen() ? 'm: merge · ' : '';
        $lines[] = '  '.$mergeAction.$statusAction.' · e: edit · c: comment · d: diff · x: delete · Esc: back';

        return $this->docWindow($lines, $cols, $rows);
    }
}

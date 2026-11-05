<?php

declare(strict_types=1);

namespace App\UI\Tui\Page\Repo;

use App\PullRequest\Domain\Model\PullRequest;
use App\PullRequest\Domain\PullRequestStatus;
use App\UI\Tui\Input\Keys;
use App\UI\Tui\Render\Screen;

/**
 * Pull Requests tab: a list of PRs with a detail view, a scrollable diff and a
 * merge-confirmation overlay backed by {@see \App\PullRequest\Application\MergePullRequest}.
 * Read-only (list only) when the repository is not registered on this server.
 */
final class PullsTab extends AbstractRepoTab
{
    private bool $prDetailOpen = false;
    private bool $prMergeConfirm = false;
    private bool $prDiffOpen = false;
    /** @var list<string> */
    private array $prDiffLines = [];
    private int $prDiffScroll = 0;

    public function capturesInput(): bool
    {
        return $this->prDiffOpen || $this->prMergeConfirm || $this->prDetailOpen;
    }

    public function onActivate(): void
    {
        parent::onActivate();
        $this->prDetailOpen = false;
        $this->prMergeConfirm = false;
        $this->prDiffOpen = false;
    }

    public function footerText(): string
    {
        if ($this->prDiffOpen) {
            return ' ↑/↓ scroll · Esc: back';
        }
        if ($this->prMergeConfirm) {
            return ' y: confirm merge · n/Esc: cancel';
        }
        if ($this->prDetailOpen) {
            return ' m: merge · d: diff · Esc: back';
        }

        return ' ↑/↓ move · Enter: view · m: merge · Tab: next · q: back';
    }

    public function statusValue(): string
    {
        return 'filter: open';
    }

    public function handleKey(string $seq): bool
    {
        if ($this->prDiffOpen) {
            return $this->handlePrDiffKey($seq);
        }
        if ($this->prMergeConfirm) {
            return $this->handlePrMergeConfirmKey($seq);
        }
        if ($this->prDetailOpen) {
            return $this->handlePrDetailKey($seq);
        }

        return $this->handlePullsTabKey(Keys::normalize($seq), Keys::text($seq));
    }

    private function handlePullsTabKey(string $tok, ?string $txt): bool
    {
        $pulls = $this->ctx->pullList();
        if (Keys::ENTER === $tok && [] !== $pulls) {
            $this->prDetailOpen = true;

            return true;
        }

        if ('m' === $txt && [] !== $pulls && null !== $this->ctx->mergePullRequest) {
            $pr = $this->selectedPull();
            if ($pr?->isOpen()) {
                $this->prMergeConfirm = true;

                return true;
            }
        }

        return $this->handleListMove($tok, $txt, \count($pulls));
    }

    private function handlePrDetailKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if (Keys::ESCAPE === $tok || 'q' === $txt) {
            $this->prDetailOpen = false;

            return true;
        }

        if ('m' === $txt && null !== ($pr = $this->selectedPull()) && $pr->isOpen() && null !== $this->ctx->mergePullRequest) {
            $this->prMergeConfirm = true;

            return true;
        }

        if ('d' === $txt && null !== ($pr = $this->selectedPull()) && null !== $this->ctx->pullRequestQuery && null !== $this->ctx->db) {
            try {
                $raw = $this->ctx->pullRequestQuery->diff($this->ctx->actor, $this->ctx->db->name(), $pr->number());
                $this->prDiffLines = preg_split('/\r\n|\r|\n/', rtrim($raw, "\n")) ?: [];
                $this->prDiffScroll = 0;
                $this->prDiffOpen = true;
            } catch (\Throwable $e) {
                $this->ctx->flash = 'Failed to load diff: '.$e->getMessage();
            }

            return true;
        }

        return false;
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

    private function handlePrDiffKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if (Keys::ESCAPE === $tok || 'q' === $txt) {
            $this->prDiffOpen = false;

            return true;
        }

        $total = \count($this->prDiffLines);
        if (Keys::UP === $tok || 'k' === $txt) {
            $this->prDiffScroll = max(0, $this->prDiffScroll - 1);
        } elseif (Keys::DOWN === $tok || 'j' === $txt) {
            $this->prDiffScroll = max(0, min($this->prDiffScroll + 1, max(0, $total - 1)));
        }

        return true;
    }

    private function confirmMerge(): void
    {
        $pr = $this->selectedPull();
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
        } catch (\Throwable $e) {
            $this->ctx->flash = 'Merge failed: '.$e->getMessage();
            $this->prMergeConfirm = false;
        }
    }

    private function selectedPull(): ?PullRequest
    {
        $pulls = $this->ctx->pullList();

        return $pulls[min($this->sel, max(0, \count($pulls) - 1))] ?? null;
    }

    // --- rendering ---------------------------------------------------------

    public function paintBody(int $cols, int $rows): array
    {
        if (null === $this->ctx->db || null === $this->ctx->pullRequestQuery) {
            return $this->messageBody('Pull requests are unavailable for local repositories.', $cols);
        }

        if ($this->prDiffOpen) {
            $header = "\e[2m  Diff for PR #".($this->selectedPull()?->number() ?? 0)."\e[0m";

            return array_merge([$header], $this->docWindow($this->colourDiff($this->prDiffLines), $cols, max(1, $rows - 1)));
        }

        if ($this->prMergeConfirm) {
            $pr = $this->selectedPull();
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

        if ($this->prDetailOpen && null !== ($pr = $this->selectedPull())) {
            return $this->paintPullDetail($pr, $cols, $rows);
        }

        $pulls = $this->ctx->pullList();
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

        if ($pr->isOpen()) {
            $lines[] = '  m: merge · d: diff · Esc: back';
        } else {
            $lines[] = '  d: diff · Esc: back';
        }

        return $this->docWindow($lines, $cols, $rows);
    }
}

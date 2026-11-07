<?php

declare(strict_types=1);

namespace App\UI\Tui\Page\Repo;

use App\Repo\Domain\Git\Commit;
use App\UI\Tui\Input\Keys;
use App\UI\Tui\Render\Screen;
use Symfony\Component\Process\Process;

/**
 * Commits tab: a list of recent commits, opening a scrollable coloured diff.
 */
final class CommitsTab extends AbstractRepoTab
{
    private bool $diffOpen = false;
    /** @var list<string> */
    private array $diffLines = [];

    /** @var array{sha: string, process: Process}|null */
    private ?array $diffJob = null;

    public function capturesInput(): bool
    {
        return $this->diffOpen || $this->fuzzyOpen;
    }

    public function onActivate(): void
    {
        parent::onActivate();
        $this->diffOpen = false;
        if (null !== $this->diffJob && $this->diffJob['process']->isRunning()) {
            $this->diffJob['process']->stop(0);
        }
        $this->diffJob = null;
    }

    public function tick(?float $deltaTime = null): bool
    {
        if (null === $this->diffJob) {
            return false;
        }

        $this->ctx->spinner->advance($deltaTime);
        if ($this->diffJob['process']->isRunning()) {
            return true;
        }

        $job = $this->diffJob;
        $this->diffJob = null;
        $proc = $job['process'];
        if ($proc->isSuccessful() && '' !== $proc->getOutput()) {
            $diff = $this->ctx->reader->parseDiffOutput($proc->getOutput());
            $this->diffLines = preg_split('/\r\n|\r|\n/', rtrim($diff->rawDiff, "\n")) ?: [];
            $this->diffOpen = true;
            $this->scroll = 0;

            return true;
        }

        try {
            $diff = $this->ctx->reader->getDiff($this->ctx->git, $job['sha']);
            $this->diffLines = preg_split('/\r\n|\r|\n/', rtrim($diff->rawDiff, "\n")) ?: [];
            $this->diffOpen = true;
            $this->scroll = 0;
        } catch (\Throwable) {
            $this->diffOpen = false;
            $this->ctx->flash = 'Cannot load diff.';
        }

        return true;
    }

    public function footerText(): string
    {
        if ($this->diffOpen) {
            return ' ↑/↓ scroll · Esc: back';
        }
        if ($this->fuzzyOpen) {
            return ' type: filter · ↑/↓: move · Enter: open · Esc: close';
        }

        return ' /: search · ' . self::BASE_FOOTER;
    }

    public function handleKey(string $seq): bool
    {
        if ($this->diffOpen) {
            return $this->handleDiffKey($seq);
        }
        if ($this->fuzzyOpen) {
            if ($this->ctx->isCommitsLoading()) {
                return $this->handleFuzzyInputKey($seq, 0, true);
            }
            $commits = $this->fuzzyCommits();

            return $this->handleFuzzyInputKey($seq, \count($commits), false, function (int $idx) use ($commits): void {
                $commit = $commits[$idx];
                $this->closeFuzzySearch();
                $this->openDiff($commit->hash);
            });
        }

        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);
        if ('/' === $txt || Keys::CTRL_P === $tok) {
            $this->openFuzzySearch();

            return true;
        }
        if ($this->ctx->isCommitsLoading()) {
            return false;
        }
        $commits = $this->ctx->commitList();
        if (Keys::ENTER === $tok && [] !== $commits) {
            $this->openDiff($commits[min($this->sel, \count($commits) - 1)]->hash);

            return true;
        }

        return $this->handleListMove($tok, $txt, \count($commits));
    }

    private function handleDiffKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);
        if (Keys::ESCAPE === $tok || 'q' === $txt) {
            if (null !== $this->diffJob && $this->diffJob['process']->isRunning()) {
                $this->diffJob['process']->stop(0);
            }
            $this->diffJob = null;
            $this->diffOpen = false;

            return true;
        }
        if (null !== $this->diffJob) {
            return false;
        }

        return $this->handleDocScroll($tok, $txt, \count($this->diffLines));
    }

    private function openDiff(string $sha): void
    {
        if (null !== $this->diffJob && $this->diffJob['process']->isRunning()) {
            $this->diffJob['process']->stop(0);
        }
        try {
            $this->diffJob = [
                'sha' => $sha,
                'process' => $this->ctx->reader->startCommitDiffProcess($this->ctx->git, $sha),
            ];
            $this->diffLines = [];
            $this->diffOpen = true;
            $this->scroll = 0;
        } catch (\Throwable) {
            $this->diffJob = null;
            $this->ctx->flash = 'Cannot load diff.';
        }
    }

    public function onClick(int $line, int $col, bool $nav = false): bool
    {
        if (!isset($this->rowToIndex[$line])) {
            return false;
        }
        $idx = $this->rowToIndex[$line];
        $commits = $this->fuzzyOpen ? $this->fuzzyCommits() : $this->ctx->commitList();
        if (!isset($commits[$idx])) {
            return false;
        }
        $cur = $this->fuzzyOpen ? $this->fuzzySel : $this->sel;
        if ($nav || $idx === $cur) {
            if ($this->fuzzyOpen) {
                $this->closeFuzzySearch();
            }
            $this->openDiff($commits[$idx]->hash);
        } elseif ($this->fuzzyOpen) {
            $this->fuzzySel = $idx;
        } else {
            $this->sel = $idx;
        }

        return true;
    }

    public function paintBody(int $cols, int $rows): array
    {
        $this->rowToIndex = [];
        if ($this->diffOpen) {
            if (null !== $this->diffJob) {
                return Screen::spinnerBody($this->ctx->spinner, 'Loading diff...', $cols);
            }

            return $this->docWindow($this->colourDiff($this->diffLines), $cols, $rows);
        }

        $headerLines = $this->fuzzyOpen ? [Screen::fuzzySearchBar($this->fuzzyQuery, $cols)] : [];
        $bodyRows = max(1, $rows - \count($headerLines));

        if ($this->ctx->isCommitsLoading()) {
            return array_merge($headerLines, Screen::spinnerBody($this->ctx->spinner, 'Loading commits...', $cols));
        }

        $commits = $this->fuzzyOpen ? $this->fuzzyCommits() : $this->ctx->commitList();
        if ([] === $commits) {
            $msg = $this->fuzzyOpen ? 'No matching commits.' : 'No commits.';

            return array_merge($headerLines, $this->messageBody($msg, $cols));
        }

        $count = \count($commits);
        $sel = min($this->fuzzyOpen ? $this->fuzzySel : $this->sel, $count - 1);
        $blocks = [];
        $blockStart = [];
        $lines = [];
        /** @var array<int,int> absolute line → commit index */
        $lineBlock = [];
        foreach ($commits as $i => $commit) {
            $meta = $commit->authorName . ' committed on ' . date('M j', $commit->timestamp);
            $blocks[] = Screen::commitItem($commit->subject, $meta, $commit->shortHash, $i === $sel, $cols);
        }
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
        $this->rowToIndex = [];
        foreach ($view as $r => $_) {
            $abs = $offset + $r;
            if (isset($lineBlock[$abs])) {
                $this->rowToIndex[$headerCount + $r] = $lineBlock[$abs];
            }
        }

        return array_merge($headerLines, $view);
    }

    /**
     * @return list<Commit>
     */
    private function fuzzyCommits(): array
    {
        $commits = $this->ctx->commitList();
        $query = trim($this->fuzzyQuery);
        if ('' === $query) {
            return $commits;
        }

        $scored = [];
        foreach ($commits as $idx => $commit) {
            $score = self::fuzzyScoreFields($query, [
                ['text' => $commit->shortHash, 'weight' => 3],
                ['text' => $commit->hash, 'weight' => 2],
                ['text' => $commit->subject, 'weight' => 3],
                ['text' => $commit->authorName, 'weight' => 2],
                ['text' => $commit->authorEmail, 'weight' => 2],
                ['text' => date('Y-m-d M j', $commit->timestamp), 'weight' => 1],
                ['text' => $commit->body, 'weight' => 1],
            ]);
            if (null !== $score) {
                $scored[] = ['commit' => $commit, 'score' => $score, 'idx' => $idx];
            }
        }

        usort($scored, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return $a['idx'] <=> $b['idx'];
        });

        return array_map(static fn(array $item): Commit => $item['commit'], $scored);
    }

    public function statusValue(): string
    {
        if ($this->fuzzyOpen) {
            return '' !== $this->fuzzyQuery ? '/' . $this->fuzzyQuery : '[SEARCH]';
        }
        if ($this->ctx->isCommitsLoading()) {
            return '';
        }
        $commits = $this->ctx->commitList();
        if ([] === $commits) {
            return '';
        }
        $c = $commits[min($this->sel, \count($commits) - 1)];

        return $c->shortHash . ' by ' . $c->authorName . ' <' . $c->authorEmail . '>';
    }

    public function statusInfo(): string
    {
        if ($this->ctx->isCommitsLoading()) {
            return '…';
        }
        if ($this->fuzzyOpen) {
            $total = \count($this->ctx->commitList());
            $count = \count($this->fuzzyCommits());
            if (0 === $count) {
                return '0/' . $total;
            }

            return 'p. ' . (min($this->fuzzySel, $count - 1) + 1) . '/' . $count;
        }
        $count = \count($this->ctx->commitList());
        if (0 === $count) {
            return '';
        }

        return 'p. ' . (min($this->sel, $count - 1) + 1) . '/' . $count;
    }
}

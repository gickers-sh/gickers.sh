<?php

declare(strict_types=1);

namespace App\UI\Tui\Page\Repo;

use App\UI\Tui\Input\Keys;
use App\UI\Tui\Render\Screen;

/**
 * Branches or Tags tab: a ref list; Enter switches the active ref.
 */
final class RefListTab extends AbstractRepoTab
{
    public function __construct(
        RepoContext $ctx,
        private readonly bool $branch,
    ) {
        parent::__construct($ctx);
    }

    public function capturesInput(): bool
    {
        return $this->fuzzyOpen;
    }

    public function footerText(): string
    {
        if ($this->fuzzyOpen) {
            return ' type: filter · ↑/↓: move · Enter: switch · Esc: close';
        }

        return ' /: search · ' . self::BASE_FOOTER;
    }

    public function handleKey(string $seq): bool
    {
        if ($this->fuzzyOpen) {
            if ($this->ctx->isRefsLoading()) {
                return $this->handleFuzzyInputKey($seq, 0, true);
            }
            $list = $this->fuzzyList();

            return $this->handleFuzzyInputKey($seq, \count($list), false, function (int $idx) use ($list): void {
                $ref = $list[$idx];
                $this->closeFuzzySearch();
                $this->ctx->switchRef($ref);
                $this->sel = 0;
                $this->scroll = 0;
            });
        }

        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);
        if ('/' === $txt || Keys::CTRL_P === $tok) {
            $this->openFuzzySearch();

            return true;
        }
        if ($this->ctx->isRefsLoading()) {
            return false;
        }
        $list = $this->list();

        if (Keys::ENTER === $tok && [] !== $list) {
            $this->ctx->switchRef($list[min($this->sel, \count($list) - 1)]);
            $this->sel = 0;
            $this->scroll = 0;

            return true;
        }

        return $this->handleListMove($tok, $txt, \count($list));
    }

    public function onClick(int $line, int $col, bool $nav = false): bool
    {
        if (!isset($this->rowToIndex[$line])) {
            return false;
        }
        $idx = $this->rowToIndex[$line];
        $list = $this->fuzzyOpen ? $this->fuzzyList() : $this->list();
        if (!isset($list[$idx])) {
            return false;
        }
        $cur = $this->fuzzyOpen ? $this->fuzzySel : $this->sel;
        if ($nav || $idx === $cur) {
            if ($this->fuzzyOpen) {
                $this->closeFuzzySearch();
            }
            $this->ctx->switchRef($list[$idx]);
            $this->sel = 0;
            $this->scroll = 0;
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
        $headerLines = $this->fuzzyOpen ? [Screen::fuzzySearchBar($this->fuzzyQuery, $cols)] : [];
        $bodyRows = max(1, $rows - \count($headerLines));

        if ($this->ctx->isRefsLoading()) {
            $label = $this->branch ? 'Loading branches...' : 'Loading tags...';

            return array_merge($headerLines, Screen::spinnerBody($this->ctx->spinner, $label, $cols));
        }

        $list = $this->fuzzyOpen ? $this->fuzzyList() : $this->list();
        if ([] === $list) {
            $empty = $this->fuzzyOpen
                ? ($this->branch ? 'No matching branches.' : 'No matching tags.')
                : ($this->branch ? 'No branches.' : 'No tags.');

            return array_merge($headerLines, $this->messageBody($empty, $cols));
        }

        $count = \count($list);
        $sel = min($this->fuzzyOpen ? $this->fuzzySel : $this->sel, $count - 1);
        $top = $count > $bodyRows ? max(0, min($sel - intdiv($bodyRows, 2), $count - $bodyRows)) : 0;

        $headerCount = \count($headerLines);
        $this->rowToIndex = [];
        $out = $headerLines;
        for ($r = 0; $r < $bodyRows; ++$r) {
            $idx = $top + $r;
            if ($idx >= $count) {
                $out[] = Screen::blank($cols);
                continue;
            }
            $this->rowToIndex[$headerCount + $r] = $idx;
            $ref = $list[$idx];
            $meta = $this->metaFor($ref);
            $out[] = Screen::refRow(
                $ref,
                null !== $meta ? $this->relativeTime($meta['time']) : '',
                null !== $meta ? substr($meta['hash'], 0, 7) : '',
                $idx === $sel,
                $cols,
            );
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function fuzzyList(): array
    {
        $list = $this->list();
        $query = trim($this->fuzzyQuery);
        if ('' === $query) {
            return $list;
        }

        $scored = [];
        foreach ($list as $idx => $ref) {
            $meta = $this->metaFor($ref);
            $hash = $meta['hash'] ?? '';
            $score = self::fuzzyScoreFields($query, [
                ['text' => $ref, 'weight' => 3],
                ['text' => substr($hash, 0, 7), 'weight' => 2],
                ['text' => $hash, 'weight' => 1],
            ]);
            if (null !== $score) {
                $scored[] = ['ref' => $ref, 'score' => $score, 'idx' => $idx];
            }
        }

        usort($scored, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return $a['idx'] <=> $b['idx'];
        });

        return array_map(static fn(array $item): string => $item['ref'], $scored);
    }

    public function statusValue(): string
    {
        if ($this->fuzzyOpen) {
            return '' !== $this->fuzzyQuery ? '/' . $this->fuzzyQuery : '[SEARCH]';
        }
        if ($this->ctx->isRefsLoading()) {
            return '';
        }
        $list = $this->list();
        if ([] === $list) {
            return '';
        }
        $ref = $list[min($this->sel, \count($list) - 1)];

        return ($this->branch ? 'refs/heads/' : 'refs/tags/') . $ref;
    }

    public function statusInfo(): string
    {
        if ($this->ctx->isRefsLoading()) {
            return '…';
        }
        if ($this->fuzzyOpen) {
            $total = \count($this->list());
            $count = \count($this->fuzzyList());
            if (0 === $count) {
                return '0/' . $total;
            }

            return 'p. ' . (min($this->fuzzySel, $count - 1) + 1) . '/' . $count;
        }
        $count = \count($this->list());
        if (0 === $count) {
            return '';
        }

        return 'p. ' . (min($this->sel, $count - 1) + 1) . '/' . $count;
    }

    /**
     * @return array{hash: string, time: int}|null
     */
    private function metaFor(string $ref): ?array
    {
        return $this->ctx->refMeta($ref);
    }

    /**
     * @return list<string>
     */
    private function list(): array
    {
        return $this->branch ? $this->ctx->branchList() : $this->ctx->tagList();
    }
}

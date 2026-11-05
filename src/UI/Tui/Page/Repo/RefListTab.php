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

    public function handleKey(string $seq): bool
    {
        if ($this->ctx->isRefsLoading()) {
            return false;
        }
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);
        $list = $this->list();

        if (Keys::ENTER === $tok && [] !== $list) {
            $this->ctx->switchRef($list[min($this->sel, \count($list) - 1)]);
            $this->sel = 0;
            $this->scroll = 0;

            return true;
        }

        return $this->handleListMove($tok, $txt, \count($list));
    }

    public function paintBody(int $cols, int $rows): array
    {
        if ($this->ctx->isRefsLoading()) {
            $label = $this->branch ? 'Loading branches...' : 'Loading tags...';

            return Screen::spinnerBody($this->ctx->spinner, $label, $cols);
        }

        $list = $this->list();
        $empty = $this->branch ? 'No branches.' : 'No tags.';
        if ([] === $list) {
            return $this->messageBody($empty, $cols);
        }

        $count = \count($list);
        $sel = min($this->sel, $count - 1);
        $top = $count > $rows ? max(0, min($sel - intdiv($rows, 2), $count - $rows)) : 0;

        $out = [];
        for ($r = 0; $r < $rows; ++$r) {
            $idx = $top + $r;
            if ($idx >= $count) {
                $out[] = Screen::blank($cols);
                continue;
            }
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

    public function statusValue(): string
    {
        if ($this->ctx->isRefsLoading()) {
            return '';
        }
        $list = $this->list();
        if ([] === $list) {
            return '';
        }
        $ref = $list[min($this->sel, \count($list) - 1)];

        return ($this->branch ? 'refs/heads/' : 'refs/tags/').$ref;
    }

    public function statusInfo(): string
    {
        if ($this->ctx->isRefsLoading()) {
            return '…';
        }
        $count = \count($this->list());
        if (0 === $count) {
            return '';
        }

        return 'p. '.(min($this->sel, $count - 1) + 1).'/'.$count;
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

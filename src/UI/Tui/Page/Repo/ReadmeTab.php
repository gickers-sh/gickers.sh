<?php

declare(strict_types=1);

namespace App\UI\Tui\Page\Repo;

use App\UI\Tui\Input\Keys;

/**
 * Readme tab: scrollable rendered README (Termstrap ANSI when available).
 */
final class ReadmeTab extends AbstractRepoTab
{
    private int $percent = 0;

    public function handleKey(string $seq): bool
    {
        return $this->handleDocScroll(Keys::normalize($seq), Keys::text($seq), $this->ctx->readmeLineCount());
    }

    public function statusInfo(): string
    {
        return '☰ '.$this->percent.'%';
    }

    public function paintBody(int $cols, int $rows): array
    {
        $lines = $this->ctx->readmeLines($cols);
        if ([] === $lines) {
            $this->percent = 0;

            return $this->messageBody('This repository has no README.', $cols);
        }

        $maxScroll = max(0, \count($lines) - $rows);
        $this->percent = $maxScroll > 0 ? (int) round(min($this->scroll, $maxScroll) / $maxScroll * 100) : 0;

        return $this->docWindow($lines, $cols, $rows);
    }
}

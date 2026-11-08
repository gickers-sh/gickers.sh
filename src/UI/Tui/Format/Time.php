<?php

declare(strict_types=1);

namespace App\UI\Tui\Format;

/**
 * Relative and human-readable time formatters.
 */
final class Time
{
    /**
     * Compact relative time format ("1m ago", "2h ago", "3d ago").
     * Accepts either a unix timestamp integer or a DateTimeImmutable instance.
     * Ported from and replaces {@see \App\UI\Tui\Page\Repo\AbstractRepoTab::relativeTime}.
     */
    public static function relative(int|\DateTimeImmutable $time): string
    {
        $timestamp = $time instanceof \DateTimeImmutable ? $time->getTimestamp() : $time;
        $diff = max(0, time() - $timestamp);
        if ($diff < 3600) {
            return max(1, intdiv($diff, 60)).'m ago';
        }
        if ($diff < 86400) {
            return intdiv($diff, 3600).'h ago';
        }

        return intdiv($diff, 86400).'d ago';
    }

    /**
     * Multi-unit relative time ("just now", "5 minutes ago", "2 days ago", "1 month ago").
     * Uses calendar/epoch seconds hierarchy (year, month, day, hour, minute).
     * Ported from and replaces {@see \App\UI\Tui\Page\SelectionPage::relativeTime}.
     */
    public static function ago(\DateTimeImmutable|int $time): string
    {
        $timestamp = $time instanceof \DateTimeImmutable ? $time->getTimestamp() : $time;
        $diff = time() - $timestamp;
        if ($diff < 60) {
            return 'just now';
        }
        foreach ([[31536000, 'year'], [2592000, 'month'], [86400, 'day'], [3600, 'hour'], [60, 'minute']] as [$secs, $unit]) {
            if ($diff >= $secs) {
                $n = intdiv($diff, $secs);

                return $n.' '.$unit.(1 === $n ? '' : 's').' ago';
            }
        }

        return 'just now';
    }

    /**
     * Human-friendly time with thresholds ("just now", "N minute(s) ago", "N hour(s) ago", "N day(s) ago").
     */
    public static function human(\DateTimeImmutable|int $time): string
    {
        $timestamp = $time instanceof \DateTimeImmutable ? $time->getTimestamp() : $time;
        $diff = max(0, time() - $timestamp);
        if ($diff < 45) {
            return 'just now';
        }
        if ($diff < 3600) {
            $m = max(1, intdiv($diff, 60));

            return $m.(1 === $m ? ' minute ago' : ' minutes ago');
        }
        if ($diff < 86400) {
            $h = max(1, intdiv($diff, 3600));

            return $h.(1 === $h ? ' hour ago' : ' hours ago');
        }
        $d = max(1, intdiv($diff, 86400));

        return $d.(1 === $d ? ' day ago' : ' days ago');
    }
}

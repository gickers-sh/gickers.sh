<?php

declare(strict_types=1);

namespace App\UI\Tui\Format;

/**
 * Fuzzy path and multi-field scoring algorithms.
 * Ported from {@see \App\UI\Tui\Page\Repo\FilesTab::fuzzyMatchPath} and
 * {@see \App\UI\Tui\Page\Repo\AbstractRepoTab::fuzzyScoreFields}.
 */
final class Fuzzy
{
    /**
     * Compute a fuzzy subsequence match of $query against $path.
     * Ported from {@see \App\UI\Tui\Page\Repo\FilesTab::fuzzyMatchPath}.
     *
     * @return array{score: int, positions: list<int>}|null
     */
    public static function path(string $query, string $path): ?array
    {
        $tokens = preg_split('/\s+/', trim($query), -1, \PREG_SPLIT_NO_EMPTY);
        if (false === $tokens || [] === $tokens) {
            return ['score' => 0, 'positions' => []];
        }

        $pathChars = preg_split('//u', $path, -1, \PREG_SPLIT_NO_EMPTY);
        if (false === $pathChars || [] === $pathChars) {
            return null;
        }
        $lowerPathChars = array_map(static fn (string $c): string => mb_strtolower($c, 'UTF-8'), $pathChars);

        $lastSlash = -1;
        foreach ($pathChars as $i => $ch) {
            if ('/' === $ch) {
                $lastSlash = $i;
            }
        }

        $totalScore = 0;
        $allPositions = [];

        foreach ($tokens as $token) {
            $m = self::fuzzyMatchSingleToken($token, $pathChars, $lowerPathChars, $lastSlash);
            if (null === $m) {
                return null;
            }
            $totalScore += $m['score'];
            foreach ($m['positions'] as $pos) {
                $allPositions[$pos] = true;
            }
        }

        // Slight penalty for longer paths so tighter matches rank first
        $totalScore -= \count($pathChars);

        $positions = array_keys($allPositions);
        sort($positions);

        return ['score' => $totalScore, 'positions' => $positions];
    }

    /**
     * Combined fuzzy score across multiple text fields.
     * Every whitespace-separated token in $query must match (via {@see self::path})
     * at least one field in $fields (with optional weight multiplier per field).
     * Ported from {@see \App\UI\Tui\Page\Repo\AbstractRepoTab::fuzzyScoreFields}.
     *
     * @param list<array{text: string, weight?: int}> $fields
     */
    public static function fields(string $query, array $fields): ?int
    {
        $tokens = preg_split('/\s+/', trim($query), -1, \PREG_SPLIT_NO_EMPTY);
        if (false === $tokens || [] === $tokens) {
            return 0;
        }

        $totalScore = 0;
        foreach ($tokens as $token) {
            $bestTokenScore = null;
            foreach ($fields as $field) {
                $text = $field['text'];
                if ('' === $text) {
                    continue;
                }
                $match = self::path($token, $text);
                if (null !== $match) {
                    $weight = $field['weight'] ?? 1;
                    $score = $match['score'] * $weight;
                    if (null === $bestTokenScore || $score > $bestTokenScore) {
                        $bestTokenScore = $score;
                    }
                }
            }
            if (null === $bestTokenScore) {
                return null;
            }
            $totalScore += $bestTokenScore;
        }

        return $totalScore;
    }

    /**
     * @param list<string> $pathChars
     * @param list<string> $lowerPathChars
     *
     * @return array{score: int, positions: list<int>}|null
     */
    private static function fuzzyMatchSingleToken(string $token, array $pathChars, array $lowerPathChars, int $lastSlash): ?array
    {
        $qChars = preg_split('//u', mb_strtolower($token, 'UTF-8'), -1, \PREG_SPLIT_NO_EMPTY);
        if (false === $qChars || [] === $qChars) {
            return ['score' => 0, 'positions' => []];
        }

        $qLen = \count($qChars);
        $pLen = \count($lowerPathChars);
        if ($qLen > $pLen) {
            return null;
        }

        // Forward check to ensure subsequence exists and find all candidate start positions for qChars[0]
        $firstPositions = [];
        for ($i = 0; $i <= $pLen - $qLen; ++$i) {
            if ($lowerPathChars[$i] === $qChars[0]) {
                $firstPositions[] = $i;
            }
        }
        if ([] === $firstPositions) {
            return null;
        }

        // Also consider suffix scan from the end (strongly favours basename matches)
        $bestResult = null;

        $backwardPositions = self::matchBackward($qChars, $lowerPathChars);
        if (null !== $backwardPositions) {
            $score = self::scorePositions($token, $pathChars, $backwardPositions, $lastSlash);
            $bestResult = ['score' => $score, 'positions' => $backwardPositions];
        }

        foreach ($firstPositions as $start) {
            $positions = self::matchFromStart($qChars, $pathChars, $lowerPathChars, $start, $lastSlash);
            if (null === $positions) {
                break;
            }
            $score = self::scorePositions($token, $pathChars, $positions, $lastSlash);
            if (null === $bestResult || $score > $bestResult['score']) {
                $bestResult = ['score' => $score, 'positions' => $positions];
            }
        }

        return $bestResult;
    }

    /**
     * @param list<string> $qChars
     * @param list<string> $lowerPathChars
     *
     * @return list<int>|null
     */
    private static function matchBackward(array $qChars, array $lowerPathChars): ?array
    {
        $qIdx = \count($qChars) - 1;
        $positions = [];
        for ($pIdx = \count($lowerPathChars) - 1; $pIdx >= 0 && $qIdx >= 0; --$pIdx) {
            if ($lowerPathChars[$pIdx] === $qChars[$qIdx]) {
                $positions[] = $pIdx;
                --$qIdx;
            }
        }
        if ($qIdx >= 0) {
            return null;
        }

        return array_reverse($positions);
    }

    /**
     * Greedy match starting at $start that prefers consecutive or boundary characters when advancing.
     *
     * @param list<string> $qChars
     * @param list<string> $pathChars
     * @param list<string> $lowerPathChars
     *
     * @return list<int>|null
     */
    private static function matchFromStart(array $qChars, array $pathChars, array $lowerPathChars, int $start, int $lastSlash): ?array
    {
        $qLen = \count($qChars);
        $pLen = \count($lowerPathChars);
        $positions = [$start];
        $cur = $start;

        for ($qi = 1; $qi < $qLen; ++$qi) {
            $target = $qChars[$qi];
            $remaining = $qLen - $qi - 1;
            $maxP = $pLen - 1 - $remaining;

            $bestP = -1;
            $bestLocalScore = \PHP_INT_MIN;
            for ($pi = $cur + 1; $pi <= $maxP; ++$pi) {
                if ($lowerPathChars[$pi] !== $target) {
                    continue;
                }
                $localScore = 0;
                if ($pi === $cur + 1) {
                    $localScore += 30;
                } else {
                    $localScore -= ($pi - $cur - 1) * 2;
                }
                if (self::isBoundary($pathChars, $pi)) {
                    $localScore += 20;
                }
                if ($pi > $lastSlash) {
                    $localScore += 15;
                }
                if ($localScore > $bestLocalScore) {
                    $bestLocalScore = $localScore;
                    $bestP = $pi;
                }
            }

            if (-1 === $bestP) {
                return null;
            }
            $positions[] = $bestP;
            $cur = $bestP;
        }

        return $positions;
    }

    /**
     * @param list<string> $pathChars
     * @param list<int>    $positions
     */
    private static function scorePositions(string $token, array $pathChars, array $positions, int $lastSlash): int
    {
        $rawTokenChars = preg_split('//u', $token, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $score = 0;
        $prev = -2;

        foreach ($positions as $k => $pos) {
            $score += 10;

            // Consecutive match bonus
            if ($pos === $prev + 1) {
                $score += 25;
            } elseif ($prev >= 0) {
                $score -= ($pos - $prev - 1) * 2;
            }

            // Boundary bonus (start of string, after '/', '_', '-', '.', or camelCase)
            if (0 === $pos) {
                $score += 20;
            } elseif ('/' === ($pathChars[$pos - 1] ?? '')) {
                $score += 25;
            } elseif (self::isBoundary($pathChars, $pos)) {
                $score += 18;
            }

            // Basename bonus (matching inside filename rather than directory prefix)
            if ($pos > $lastSlash) {
                $score += 15;
                if ($pos === $lastSlash + 1) {
                    $score += 15;
                }
            }

            // Exact case bonus
            if (isset($rawTokenChars[$k], $pathChars[$pos]) && $rawTokenChars[$k] === $pathChars[$pos]) {
                $score += 2;
            }

            $prev = $pos;
        }

        // Exact basename match bonus
        $basenameStart = $lastSlash + 1;
        $basenameLen = \count($pathChars) - $basenameStart;
        if (\count($positions) === $basenameLen && ($positions[0] ?? -1) === $basenameStart) {
            $score += 50;
        }

        return $score;
    }

    /**
     * @param list<string> $pathChars
     */
    private static function isBoundary(array $pathChars, int $pos): bool
    {
        if ($pos <= 0) {
            return true;
        }
        $prev = $pathChars[$pos - 1] ?? '';
        if ('/' === $prev || '_' === $prev || '-' === $prev || '.' === $prev || ' ' === $prev) {
            return true;
        }
        $cur = $pathChars[$pos] ?? '';

        return ctype_lower($prev) && ctype_upper($cur);
    }
}

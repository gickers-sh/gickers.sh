<?php

declare(strict_types=1);

namespace App\UI\Tui\Page\Repo;

use App\UI\Tui\Input\Keys;
use App\UI\Tui\Render\Screen;

/**
 * Shared base for repository tabs: per-tab selection/scroll state, list and
 * document navigation, and the small rendering helpers (windowing, diff
 * colouring, byte/time formatting) used across tabs.
 */
abstract class AbstractRepoTab implements RepoTab
{
    protected const BASE_FOOTER = ' Tab: next · 1–7: jump · Enter: open · q: back';

    protected int $sel = 0;
    protected int $scroll = 0;
    protected int $rows = 20;
    /** @var array<int,int> painted-body-line → index into the currently displayed list (set by list tabs for click hit-testing) */
    protected array $rowToIndex = [];
    protected bool $fuzzyOpen = false;
    protected string $fuzzyQuery = '';
    protected int $fuzzySel = 0;

    public function __construct(protected readonly RepoContext $ctx) {}

    public function footerText(): string
    {
        return self::BASE_FOOTER;
    }

    public function statusValue(): string
    {
        return '';
    }

    public function statusInfo(): string
    {
        return '';
    }

    public function capturesInput(): bool
    {
        return false;
    }

    public function onActivate(): void
    {
        $this->sel = 0;
        $this->scroll = 0;
        $this->closeFuzzySearch();
    }

    public function tick(?float $deltaTime = null): bool
    {
        return false;
    }

    public function onClick(int $line, int $col, bool $nav = false): bool
    {
        return false;
    }

    public function customTabBarLines(int $cols): ?array
    {
        return null;
    }

    public function isFullScreenModal(): bool
    {
        return false;
    }

    public function statusBranch(): ?string
    {
        return null;
    }

    protected function handleDocScroll(string $tok, ?string $txt, int $total): bool
    {
        $maxScroll = max(0, $total - ($this->rows > 0 ? $this->rows : 20));
        $pageStep = max(1, $this->rows > 0 ? $this->rows - 2 : 10);
        $halfStep = max(1, (int) round(($this->rows > 0 ? $this->rows : 20) / 2));

        if (Keys::UP === $tok || 'k' === $txt) {
            $this->scroll = max(0, $this->scroll - 1);
        } elseif (Keys::DOWN === $tok || 'j' === $txt) {
            $this->scroll = max(0, min($this->scroll + 1, $maxScroll));
        } elseif (Keys::CTRL_D === $tok || 'd' === $txt) {
            $this->scroll = max(0, min($this->scroll + $halfStep, $maxScroll));
        } elseif (Keys::CTRL_U === $tok || 'u' === $txt) {
            $this->scroll = max(0, $this->scroll - $halfStep);
        } elseif (Keys::PAGE_DOWN === $tok || Keys::CTRL_F === $tok || 'f' === $txt || Keys::SPACE === $tok) {
            $this->scroll = max(0, min($this->scroll + $pageStep, $maxScroll));
        } elseif (Keys::PAGE_UP === $tok || Keys::CTRL_B === $tok || 'b' === $txt) {
            $this->scroll = max(0, $this->scroll - $pageStep);
        } elseif (Keys::HOME === $tok || 'g' === $txt) {
            $this->scroll = 0;
        } elseif (Keys::END === $tok || 'G' === $txt) {
            $this->scroll = $maxScroll;
        } else {
            return false;
        }

        return true;
    }

    protected function handleListMove(string $tok, ?string $txt, int $count): bool
    {
        if (Keys::UP === $tok || 'k' === $txt) {
            $this->sel = max(0, $this->sel - 1);
        } elseif (Keys::DOWN === $tok || 'j' === $txt) {
            $this->sel = max(0, min($this->sel + 1, max(0, $count - 1)));
        } elseif (Keys::PAGE_UP === $tok) {
            $this->sel = max(0, $this->sel - 10);
        } elseif (Keys::PAGE_DOWN === $tok) {
            $this->sel = max(0, min($this->sel + 10, max(0, $count - 1)));
        } elseif (Keys::HOME === $tok || 'g' === $txt) {
            $this->sel = 0;
        } elseif (Keys::END === $tok || 'G' === $txt) {
            $this->sel = max(0, $count - 1);
        } else {
            return false;
        }

        return true;
    }

    protected function openFuzzySearch(): void
    {
        $this->fuzzyOpen = true;
        $this->fuzzyQuery = '';
        $this->fuzzySel = 0;
    }

    protected function closeFuzzySearch(): void
    {
        $this->fuzzyOpen = false;
        $this->fuzzyQuery = '';
        $this->fuzzySel = 0;
    }

    /**
     * Handles common fuzzy search navigation and text editing keys.
     * Calls $onSelect($selectedIndex) when Enter is pressed and $count > 0.
     *
     * @param ?\Closure(int): void $onSelect
     */
    protected function handleFuzzyInputKey(string $seq, int $count, bool $loading = false, ?\Closure $onSelect = null): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if (Keys::ESCAPE === $tok) {
            $this->closeFuzzySearch();

            return true;
        }

        if (Keys::CTRL_U === $tok) {
            $this->fuzzyQuery = '';
            $this->fuzzySel = 0;

            return true;
        }

        if (Keys::BACKSPACE === $tok) {
            if ('' !== $this->fuzzyQuery) {
                $this->fuzzyQuery = mb_substr($this->fuzzyQuery, 0, -1);
                $this->fuzzySel = 0;
            } else {
                $this->closeFuzzySearch();
            }

            return true;
        }

        if ($loading) {
            if (null !== $txt && '' !== $txt) {
                $this->fuzzyQuery .= $txt;
                $this->fuzzySel = 0;

                return true;
            }

            return false;
        }

        if (Keys::UP === $tok || Keys::CTRL_P === $tok) {
            $this->fuzzySel = max(0, $this->fuzzySel - 1);

            return true;
        }

        if (Keys::DOWN === $tok || Keys::CTRL_N === $tok) {
            $this->fuzzySel = max(0, min($this->fuzzySel + 1, max(0, $count - 1)));

            return true;
        }

        if (Keys::PAGE_UP === $tok) {
            $this->fuzzySel = max(0, $this->fuzzySel - 10);

            return true;
        }

        if (Keys::PAGE_DOWN === $tok) {
            $this->fuzzySel = max(0, min($this->fuzzySel + 10, max(0, $count - 1)));

            return true;
        }

        if (Keys::HOME === $tok) {
            $this->fuzzySel = 0;

            return true;
        }

        if (Keys::END === $tok) {
            $this->fuzzySel = max(0, $count - 1);

            return true;
        }

        if (Keys::ENTER === $tok) {
            if ($count > 0 && null !== $onSelect) {
                $idx = min($this->fuzzySel, $count - 1);
                $onSelect($idx);
            }

            return true;
        }

        if (null !== $txt && '' !== $txt) {
            $this->fuzzyQuery .= $txt;
            $this->fuzzySel = 0;

            return true;
        }

        return false;
    }

    /**
     * Multi-field fuzzy score: every space-separated token in $query must match
     * at least one field in $fields (with optional weight multiplier per field).
     *
     * @param list<array{text: string, weight?: int}> $fields
     */
    public static function fuzzyScoreFields(string $query, array $fields): ?int
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
                $match = FilesTab::fuzzyMatchPath($token, $text);
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
     * A body consisting of a single informational line near the top.
     *
     * @return list<string>
     */
    protected function messageBody(string $message, int $cols): array
    {
        return [Screen::blank($cols), Screen::fit('  ' . $message, $cols)];
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    protected function docWindow(array $lines, int $cols, int $rows): array
    {
        $this->rows = max(1, $rows);
        $total = \count($lines);
        $maxScroll = max(0, $total - $rows);
        $top = max(0, min($this->scroll, $maxScroll));
        $out = [];
        for ($r = 0; $r < $rows; ++$r) {
            $idx = $top + $r;
            $out[] = $idx < $total ? Screen::fit($lines[$idx], $cols) : Screen::blank($cols);
        }

        return $out;
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    protected function colourDiff(array $lines): array
    {
        return array_map(static function (string $line): string {
            if (str_starts_with($line, '+') && !str_starts_with($line, '+++')) {
                return "\e[32m" . $line . "\e[0m";
            }
            if (str_starts_with($line, '-') && !str_starts_with($line, '---')) {
                return "\e[31m" . $line . "\e[0m";
            }
            if (str_starts_with($line, '@@')) {
                return "\e[36m" . $line . "\e[0m";
            }

            return $line;
        }, $lines);
    }

    protected function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / (1024 * 1024), 1) . ' MB';
    }

    protected function relativeTime(int $timestamp): string
    {
        $diff = max(0, time() - $timestamp);
        if ($diff < 3600) {
            return max(1, intdiv($diff, 60)) . 'm ago';
        }
        if ($diff < 86400) {
            return intdiv($diff, 3600) . 'h ago';
        }

        return intdiv($diff, 86400) . 'd ago';
    }
}

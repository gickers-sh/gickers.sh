<?php

declare(strict_types=1);

namespace App\UI\Tui\Page\Repo;

use App\CodeIntel\Domain\SymbolLocation;
use App\Repo\Domain\Git\TreeEntry;
use App\UI\Tui\Input\Keys;
use App\UI\Tui\Render\CodeHighlighter;
use App\UI\Tui\Render\CommitForm;
use App\UI\Tui\Render\Editor;
use App\UI\Tui\Render\Screen;
use App\UI\Tui\Render\Theme;
use Symfony\Component\Process\Process;

/**
 * Files tab: a directory browser over the current ref, opening a
 * syntax-highlighted code viewer with keyboard go-to-definition ('d') and
 * find-references ('r') backed by {@see \App\CodeIntel\Application\Query\CodeIntelQuery}.
 */
final class FilesTab extends AbstractRepoTab
{
    private bool $fileOpen = false;
    private string $filePath = '';
    /** @var array{path: string, process: Process, targetLine: int, targetCol: int, flash?: string}|null */
    private ?array $blobJob = null;
    /** @var list<string> */
    private array $fileRaw = [];
    private int $cursorLine = 0;
    private int $cursorCol = 0;
    private bool $filePreview = false;
    private int $filePreviewScroll = 0;
    /** @var array{key: string, lines: list<string>}|null */
    private ?array $filePreviewCache = null;
    /** @var array{key: string, process: Process}|null */
    private ?array $filePreviewJob = null;
    private int $cols = 80;

    /** @var list<string>|null ANSI-highlighted lines parallel to; null = plain text */
    private ?array $highlighted = null;
    private ?CodeHighlighter $highlighter = null;
    /** @var array{path: string, process: Process}|null */
    private ?array $highlightJob = null;
    /** @var array{path: string, process: Process}|null */
    private ?array $editorHighlightJob = null;

    private bool $refsOpen = false;
    /** @var list<SymbolLocation> */
    private array $refs = [];
    private int $refSel = 0;
    /** @var array<string, list<string>> path => raw file lines */
    private array $refsPreviewCache = [];
    /** @var array{path: string, process: Process}|null */
    private ?array $refsPreviewJob = null;
    private string $refsTitle = '';
    /** First source line painted in the file viewer (for click hit-testing). */
    private int $fileTop = 0;
    /** Gutter width of the file viewer (digits before the code column). */
    private int $fileGutter = 3;

    // --- Write flows: states and buffers ---
    private bool $editing = false;
    private bool $isNewFile = false;
    private string $editPath = '';
    /** @var list<string>|null ANSI-highlighted lines parallel to editor lines; null = plain text or dirty */
    private ?array $editorHighlighted = null;
    private bool $editorDirty = true;

    private bool $newFilePrompt = false;
    private string $newFilePathInput = '';

    private bool $confirmDelete = false;
    private string $deleteTargetPath = '';

    private bool $commitPrompt = false;
    private string $commitAction = 'update'; // 'update' | 'create' | 'delete'

    private Editor $editor;
    private CommitForm $commitForm;

    public function __construct(RepoContext $ctx)
    {
        parent::__construct($ctx);
        $this->editor = new Editor();
        $this->editor->setSpinner($ctx->spinner);
        $this->commitForm = new CommitForm();
    }

    public function capturesInput(): bool
    {
        return $this->fileOpen
            || $this->refsOpen
            || $this->editing
            || $this->newFilePrompt
            || $this->confirmDelete
            || $this->commitPrompt
            || $this->fuzzyOpen;
    }

    public function onActivate(): void
    {
        parent::onActivate();
        $this->fileOpen = false;
        if (null !== $this->blobJob && $this->blobJob['process']->isRunning()) {
            $this->blobJob['process']->stop(0);
        }
        $this->blobJob = null;
        $this->refsOpen = false;
        if (null !== $this->refsPreviewJob && $this->refsPreviewJob['process']->isRunning()) {
            $this->refsPreviewJob['process']->stop(0);
        }
        $this->refsPreviewJob = null;
        $this->refsPreviewCache = [];
        $this->refsTitle = '';
        $this->editing = false;
        $this->editor->stopJobs();
        $this->newFilePrompt = false;
        $this->confirmDelete = false;
        $this->commitPrompt = false;
        $this->filePreview = false;
        $this->filePreviewScroll = 0;
        $this->filePreviewCache = null;
        if (null !== $this->filePreviewJob && $this->filePreviewJob['process']->isRunning()) {
            $this->filePreviewJob['process']->stop(0);
        }
        $this->filePreviewJob = null;
        if (null !== $this->highlightJob && $this->highlightJob['process']->isRunning()) {
            $this->highlightJob['process']->stop(0);
        }
        $this->highlightJob = null;
        if (null !== $this->editorHighlightJob && $this->editorHighlightJob['process']->isRunning()) {
            $this->editorHighlightJob['process']->stop(0);
        }
        $this->editorHighlightJob = null;
    }

    public function tick(?float $deltaTime = null): bool
    {
        $busy = false;

        if (null !== $this->blobJob) {
            $busy = true;
            $this->pollBlobJob();
        }

        if (null !== $this->highlightJob) {
            $busy = true;
            $this->pollHighlightJob();
        }

        if (null !== $this->editorHighlightJob) {
            $busy = true;
            $this->pollEditorHighlightJob();
        }

        if (null !== $this->filePreviewJob) {
            $busy = true;
            $this->pollFilePreviewJob();
        }

        if (null !== $this->refsPreviewJob) {
            $busy = true;
            $this->pollRefsPreviewJob();
        }

        if ($this->editing && $this->editor->tick($deltaTime)) {
            $busy = true;
        }

        if ($busy) {
            $this->ctx->spinner->advance($deltaTime);
        }

        return $busy;
    }

    private function pollBlobJob(): void
    {
        if (null === $this->blobJob || $this->blobJob['process']->isRunning()) {
            return;
        }

        $job = $this->blobJob;
        $this->blobJob = null;
        $proc = $job['process'];
        if (!$proc->isSuccessful()) {
            $this->fileOpen = false;
            $this->ctx->flash = 'Cannot open ' . $job['path'];

            return;
        }

        $this->loadFile($job['path'], $proc->getOutput());
        $this->fileOpen = true;
        $this->cursorLine = max(0, min($job['targetLine'], max(0, \count($this->fileRaw) - 1)));
        $this->cursorCol = max(0, $job['targetCol']);
        if (isset($job['flash'])) {
            $this->ctx->flash = $job['flash'];
        }
    }

    private function pollFilePreviewJob(): void
    {
        if (null === $this->filePreviewJob || $this->filePreviewJob['process']->isRunning()) {
            return;
        }

        $job = $this->filePreviewJob;
        $this->filePreviewJob = null;
        $proc = $job['process'];
        $lines = $proc->isSuccessful()
            ? (preg_split('/\r\n|\r|\n/', rtrim($proc->getOutput(), "\n")) ?: [])
            : $this->fileRaw;
        $this->filePreviewCache = ['key' => $job['key'], 'lines' => $lines];
    }

    private function pollHighlightJob(): void
    {
        if (null === $this->highlightJob || $this->highlightJob['process']->isRunning()) {
            return;
        }

        $job = $this->highlightJob;
        $this->highlightJob = null;
        $proc = $job['process'];
        if ($proc->isSuccessful() && $job['path'] === $this->filePath) {
            $decoded = json_decode($proc->getOutput(), true);
            if (\is_array($decoded) && \count($decoded) === \count($this->fileRaw)) {
                $lines = [];
                foreach ($decoded as $val) {
                    $lines[] = \is_string($val) ? $val : '';
                }
                $this->highlighted = $lines;
            }
        }
    }

    private function pollEditorHighlightJob(): void
    {
        if (null === $this->editorHighlightJob || $this->editorHighlightJob['process']->isRunning()) {
            return;
        }

        $job = $this->editorHighlightJob;
        $this->editorHighlightJob = null;
        $proc = $job['process'];
        if ($proc->isSuccessful() && $job['path'] === $this->editPath) {
            $decoded = json_decode($proc->getOutput(), true);
            if (\is_array($decoded)) {
                $lines = [];
                foreach ($decoded as $val) {
                    $lines[] = \is_string($val) ? $val : '';
                }
                $count = \count($this->editor->lines());
                while (\count($lines) < $count) {
                    $lines[] = '';
                }
                $this->editorHighlighted = \count($lines) === $count ? $lines : null;
            }
        }
    }

    private function pollRefsPreviewJob(): void
    {
        if (null === $this->refsPreviewJob || $this->refsPreviewJob['process']->isRunning()) {
            return;
        }

        $job = $this->refsPreviewJob;
        $this->refsPreviewJob = null;
        $proc = $job['process'];
        if ($proc->isSuccessful()) {
            $lines = preg_split('/\r\n|\r|\n/', rtrim($proc->getOutput(), "\n")) ?: [];
            $this->refsPreviewCache[$job['path']] = $lines;
        }
    }

    public function footerText(): string
    {
        if ($this->commitPrompt) {
            return ' Tab/↑/↓: field · Enter/Ctrl+S: commit · Esc: cancel';
        }
        if ($this->confirmDelete) {
            return ' y: confirm delete · n/Esc: cancel';
        }
        if ($this->newFilePrompt) {
            return ' Enter: create · Esc: cancel';
        }
        if ($this->editing) {
            $hint = $this->editor->previewSupported()
                ? ('Ctrl+P: ' . $this->editor->previewMode() . ' · ')
                : '';

            return sprintf(' Ln %d, Col %d · %sCtrl+S: save · Esc: discard', $this->editor->line() + 1, $this->editor->col() + 1, $hint);
        }
        if ($this->refsOpen) {
            return ' ↑/↓ move · Enter: jump · Esc: back';
        }
        if ($this->fileOpen) {
            $pHint = $this->isMarkdownFile()
                ? ($this->filePreview ? 'p: raw · ' : 'p: preview · ')
                : '';

            return sprintf(' Ln %d, Col %d · %se: edit · d: definition · r: references · Esc: back', $this->cursorLine + 1, $this->cursorCol + 1, $pHint);
        }
        if ($this->fuzzyOpen) {
            return ' type: filter · ↑/↓: move · Enter: open · Esc: close';
        }

        return ' /: search · n: new · D: delete · ' . self::BASE_FOOTER;
    }

    public function statusValue(): string
    {
        if ($this->commitPrompt) {
            return '[COMMIT]';
        }
        if ($this->confirmDelete) {
            return '[DELETE]';
        }
        if ($this->newFilePrompt) {
            return '[NEW FILE]';
        }
        if ($this->editing) {
            return $this->editPath . ($this->isNewFile ? ' [NEW]' : ' [EDIT]');
        }
        if ($this->fileOpen && $this->filePreview) {
            return '[PREVIEW]';
        }
        if ($this->fuzzyOpen) {
            return '' !== $this->fuzzyQuery ? '/' . $this->fuzzyQuery : '[SEARCH]';
        }
        if ($this->refsOpen) {
            return '' !== $this->refsTitle ? $this->refsTitle : '[REFERENCES]';
        }

        return parent::statusValue();
    }

    public function handleKey(string $seq): bool
    {
        if ($this->commitPrompt) {
            return $this->handleCommitPromptKey($seq);
        }
        if ($this->confirmDelete) {
            return $this->handleConfirmDeleteKey($seq);
        }
        if ($this->newFilePrompt) {
            return $this->handleNewFilePromptKey($seq);
        }
        if ($this->editing) {
            return $this->handleEditorKey($seq);
        }
        if ($this->refsOpen) {
            return $this->handleRefsKey($seq);
        }
        if ($this->fileOpen) {
            return $this->handleFileKey($seq);
        }
        if ($this->fuzzyOpen) {
            return $this->handleFuzzyKey($seq);
        }

        return $this->handleTabKey(Keys::normalize($seq), Keys::text($seq));
    }

    private function handleTabKey(string $tok, ?string $txt): bool
    {
        if ($this->ctx->isTreeLoading()) {
            if ((Keys::BACKSPACE === $tok || Keys::LEFT === $tok || 'h' === $txt) && '' !== $this->ctx->subpath) {
                $this->ctx->subpath = $this->parentPath($this->ctx->subpath);
                $this->sel = 0;

                return true;
            }

            return false;
        }

        if ('/' === $txt || Keys::CTRL_P === $tok) {
            $this->openFuzzySearch();

            return true;
        }
        $entries = $this->ctx->tree();
        if ('n' === $txt) {
            if (!$this->checkCanWrite()) {
                return true;
            }
            $this->newFilePrompt = true;
            $this->newFilePathInput = '' !== $this->ctx->subpath ? rtrim($this->ctx->subpath, '/') . '/' : '';

            return true;
        }
        if ('D' === $txt) {
            if (!$this->checkCanWrite()) {
                return true;
            }
            if ([] === $entries) {
                return true;
            }
            $entry = $entries[min($this->sel, \count($entries) - 1)];
            if ($entry->isDirectory()) {
                $this->ctx->flash = 'Cannot delete a directory.';

                return true;
            }
            $this->deleteTargetPath = $entry->path;
            $this->confirmDelete = true;

            return true;
        }

        if ((Keys::BACKSPACE === $tok || Keys::LEFT === $tok || 'h' === $txt) && '' !== $this->ctx->subpath) {
            $this->ctx->subpath = $this->parentPath($this->ctx->subpath);
            $this->sel = 0;

            return true;
        }
        if ((Keys::ENTER === $tok || Keys::RIGHT === $tok || 'l' === $txt) && [] !== $entries) {
            $entry = $entries[min($this->sel, \count($entries) - 1)];
            if ($entry->isDirectory()) {
                $this->ctx->subpath = $entry->path;
                $this->sel = 0;
            } else {
                $this->openFile($entry->path);
            }

            return true;
        }

        return $this->handleListMove($tok, $txt, \count($entries));
    }

    private function handleFileKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);
        $last = max(0, \count($this->fileRaw) - 1);

        if (Keys::ESCAPE === $tok || 'q' === $txt) {
            if (null !== $this->blobJob && $this->blobJob['process']->isRunning()) {
                $this->blobJob['process']->stop(0);
            }
            $this->blobJob = null;
            if (null !== $this->filePreviewJob && $this->filePreviewJob['process']->isRunning()) {
                $this->filePreviewJob['process']->stop(0);
            }
            $this->filePreviewJob = null;
            if (null !== $this->highlightJob && $this->highlightJob['process']->isRunning()) {
                $this->highlightJob['process']->stop(0);
            }
            $this->highlightJob = null;
            $this->fileOpen = false;
            $this->filePreview = false;
            $this->filePreviewScroll = 0;

            return true;
        }
        if (null !== $this->blobJob) {
            return false;
        }

        if ('p' === $txt && $this->isMarkdownFile()) {
            $this->filePreview = !$this->filePreview;
            $this->filePreviewScroll = 0;

            return true;
        }

        if ($this->filePreview) {
            if ($this->isFilePreviewLoading($this->cols > 0 ? $this->cols : 80)) {
                return false;
            }
            $lines = $this->filePreviewLines($this->cols > 0 ? $this->cols : 80);
            $total = \count($lines);
            $bodyRows = max(1, ($this->rows > 0 ? $this->rows : 24) - 1);
            $maxScroll = max(0, $total - $bodyRows);
            $pageStep = max(1, $bodyRows - 2);

            if (Keys::UP === $tok || 'k' === $txt) {
                $this->filePreviewScroll = max(0, $this->filePreviewScroll - 1);
            } elseif (Keys::DOWN === $tok || 'j' === $txt) {
                $this->filePreviewScroll = max(0, min($this->filePreviewScroll + 1, $maxScroll));
            } elseif (Keys::PAGE_UP === $tok || Keys::CTRL_U === $tok || Keys::CTRL_B === $tok || 'b' === $txt) {
                $this->filePreviewScroll = max(0, $this->filePreviewScroll - $pageStep);
            } elseif (Keys::PAGE_DOWN === $tok || Keys::CTRL_D === $tok || Keys::CTRL_F === $tok || Keys::SPACE === $tok || 'f' === $txt) {
                $this->filePreviewScroll = max(0, min($this->filePreviewScroll + $pageStep, $maxScroll));
            } elseif (Keys::HOME === $tok || 'g' === $txt) {
                $this->filePreviewScroll = 0;
            } elseif (Keys::END === $tok || 'G' === $txt) {
                $this->filePreviewScroll = $maxScroll;
            } elseif ('e' === $txt) {
                if (!$this->checkCanWrite()) {
                    return true;
                }
                $this->startEditing();

                return true;
            } elseif ('D' === $txt) {
                if (!$this->checkCanWrite()) {
                    return true;
                }
                $this->deleteTargetPath = $this->filePath;
                $this->confirmDelete = true;

                return true;
            } else {
                return false;
            }

            return true;
        }
        if (Keys::UP === $tok || 'k' === $txt) {
            $this->cursorLine = max(0, $this->cursorLine - 1);
        } elseif (Keys::DOWN === $tok || 'j' === $txt) {
            $this->cursorLine = min($last, $this->cursorLine + 1);
        } elseif (Keys::LEFT === $tok || 'h' === $txt) {
            $this->cursorCol = max(0, $this->cursorCol - 1);
        } elseif (Keys::RIGHT === $tok || 'l' === $txt) {
            $this->cursorCol = min($this->currentLineLength(), $this->cursorCol + 1);
        } elseif (Keys::PAGE_UP === $tok || Keys::CTRL_U === $tok || Keys::CTRL_B === $tok) {
            $this->cursorLine = max(0, $this->cursorLine - 10);
        } elseif (Keys::PAGE_DOWN === $tok || Keys::CTRL_D === $tok || Keys::CTRL_F === $tok || Keys::SPACE === $tok) {
            $this->cursorLine = min($last, $this->cursorLine + 10);
        } elseif (Keys::HOME === $tok || 'g' === $txt) {
            $this->cursorLine = 0;
        } elseif (Keys::END === $tok || 'G' === $txt) {
            $this->cursorLine = $last;
        } elseif ('d' === $txt) {
            $this->gotoDefinition();
        } elseif ('r' === $txt) {
            $this->findReferences();
        } elseif ('e' === $txt) {
            if (!$this->checkCanWrite()) {
                return true;
            }
            $this->startEditing();

            return true;
        } elseif ('D' === $txt) {
            if (!$this->checkCanWrite()) {
                return true;
            }
            $this->deleteTargetPath = $this->filePath;
            $this->confirmDelete = true;

            return true;
        } else {
            return false;
        }
        $this->cursorCol = min($this->cursorCol, $this->currentLineLength());

        return true;
    }

    private function handleRefsKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if (Keys::ESCAPE === $tok || 'q' === $txt) {
            $this->refsOpen = false;
        } elseif (Keys::UP === $tok || 'k' === $txt) {
            $this->refSel = max(0, $this->refSel - 1);
            $this->ensureRefPreview();
        } elseif (Keys::DOWN === $tok || 'j' === $txt) {
            $this->refSel = max(0, min($this->refSel + 1, \count($this->refs) - 1));
            $this->ensureRefPreview();
        } elseif (Keys::ENTER === $tok && [] !== $this->refs) {
            $this->refsOpen = false;
            $this->openLocation($this->refs[min($this->refSel, \count($this->refs) - 1)]);
        } else {
            return false;
        }

        return true;
    }

    private function handleFuzzyKey(string $seq): bool
    {
        if ($this->ctx->isRecursiveTreeLoading()) {
            return $this->handleFuzzyInputKey($seq, 0, true);
        }

        $matches = $this->fuzzyMatches();

        return $this->handleFuzzyInputKey($seq, \count($matches), false, function (int $idx) use ($matches): void {
            $selected = $matches[$idx]['entry'];
            $this->closeFuzzySearch();
            $this->openFile($selected->path);
        });
    }

    /**
     * Computes fuzzy-matched file entries against {@see $fuzzyQuery}.
     *
     * @return list<array{entry: TreeEntry, score: int, positions: list<int>}>
     */
    private function fuzzyMatches(): array
    {
        $entries = $this->ctx->recursiveTree();
        $query = trim($this->fuzzyQuery);
        if ('' === $query) {
            $out = [];
            foreach ($entries as $entry) {
                $out[] = ['entry' => $entry, 'score' => 0, 'positions' => []];
            }

            return $out;
        }

        $scored = [];
        foreach ($entries as $idx => $entry) {
            $match = self::fuzzyMatchPath($query, $entry->path);
            if (null !== $match) {
                $scored[] = [
                    'entry' => $entry,
                    'score' => $match['score'],
                    'positions' => $match['positions'],
                    'idx' => $idx,
                ];
            }
        }

        usort($scored, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            $lenCmp = \strlen($a['entry']->path) <=> \strlen($b['entry']->path);
            if (0 !== $lenCmp) {
                return $lenCmp;
            }

            return $a['idx'] <=> $b['idx'];
        });

        $out = [];
        foreach ($scored as $item) {
            $out[] = [
                'entry' => $item['entry'],
                'score' => $item['score'],
                'positions' => $item['positions'],
            ];
        }

        return $out;
    }

    /**
     * Fuzzy-matches a query against a filepath, returning a score and matched character indices, or null.
     * Space-separated tokens in the query must all match as fuzzy subsequences.
     *
     * @return array{score: int, positions: list<int>}|null
     */
    public static function fuzzyMatchPath(string $query, string $path): ?array
    {
        $tokens = preg_split('/\s+/', trim($query), -1, \PREG_SPLIT_NO_EMPTY);
        if (false === $tokens || [] === $tokens) {
            return ['score' => 0, 'positions' => []];
        }

        $pathChars = preg_split('//u', $path, -1, \PREG_SPLIT_NO_EMPTY);
        if (false === $pathChars || [] === $pathChars) {
            return null;
        }
        $lowerPathChars = array_map(static fn(string $c): string => mb_strtolower($c, 'UTF-8'), $pathChars);

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

    // --- actions -----------------------------------------------------------

    private function openFile(string $path, bool $async = true, int $targetLine = 0, int $targetCol = 0, ?string $flash = null): void
    {
        if (null !== $this->blobJob && $this->blobJob['process']->isRunning()) {
            $this->blobJob['process']->stop(0);
        }
        $this->blobJob = null;

        if ($async) {
            try {
                $proc = $this->ctx->reader->startBlobProcess($this->ctx->git, $this->ctx->ref, $path);
                $job = [
                    'path' => $path,
                    'process' => $proc,
                    'targetLine' => $targetLine,
                    'targetCol' => $targetCol,
                ];
                if (null !== $flash) {
                    $job['flash'] = $flash;
                }
                $this->blobJob = $job;
                $this->filePath = $path;
                $this->fileOpen = true;
                $this->cursorLine = $targetLine;
                $this->cursorCol = $targetCol;

                return;
            } catch (\Throwable) {
                $this->ctx->flash = 'Cannot open ' . $path;

                return;
            }
        }

        try {
            $content = $this->ctx->reader->getBlob($this->ctx->git, $this->ctx->ref, $path);
        } catch (\Throwable) {
            $this->ctx->flash = 'Cannot open ' . $path;

            return;
        }
        $this->loadFile($path, $content);
        $this->fileOpen = true;
        $this->cursorLine = max(0, min($targetLine, max(0, \count($this->fileRaw) - 1)));
        $this->cursorCol = max(0, $targetCol);
        if (null !== $flash) {
            $this->ctx->flash = $flash;
        }
    }

    /**
     * Ctrl/Alt/Cmd+click (or middle/right click) on a symbol in the open file:
     * clicking a usage jumps to its definition, clicking a definition lists its
     * references. Mirrors Go `symbolNavCmds`. Returns false when the click is not
     * a symbol-navigation gesture so the caller can fall through to selection.
     */
    private function navigateSymbolClick(int $line, int $col): bool
    {
        if (!$this->fileOpen || $this->filePreview || $this->editing || $this->refsOpen) {
            return false;
        }
        if ($line < 1) {
            return false; // crumb header row
        }
        $idx = $this->fileTop + ($line - 1);
        if ($idx < 0 || $idx >= \count($this->fileRaw)) {
            return false;
        }
        $srcCol = max(0, $col - ($this->fileGutter + 2));
        $this->cursorLine = $idx;
        $this->cursorCol = $srcCol;

        return $this->navigateSymbolAt($idx, $srcCol);
    }

    private function navigateSymbolAt(int $line, int $col): bool
    {
        $sha = $this->ctx->sha();
        if ('' === $sha || !$this->ctx->codeIntel->has($this->ctx->name, $sha)) {
            $this->ctx->flash = 'No code intelligence index for this commit.';

            return true;
        }
        $hit = null;
        foreach ($this->ctx->codeIntel->occurrences($this->ctx->name, $sha, $this->filePath) as $occ) {
            if ($occ->contains($line, $col)) {
                $hit = $occ;
                break;
            }
        }
        if (null === $hit) {
            $this->ctx->flash = 'No symbol under click.';

            return true;
        }
        if ($hit->isDefinition) {
            $this->findReferences();
        } else {
            $this->gotoDefinition();
        }

        return true;
    }

    private function gotoDefinition(): void
    {
        $sha = $this->ctx->sha();
        if ('' === $sha || !$this->ctx->codeIntel->has($this->ctx->name, $sha)) {
            $this->ctx->flash = 'No code intelligence index for this commit.';

            return;
        }
        $def = $this->ctx->codeIntel->findDefinition($this->ctx->name, $sha, $this->filePath, $this->cursorLine, $this->cursorCol, true);
        if (null === $def) {
            $this->ctx->flash = 'No definition found at cursor.';

            return;
        }
        $this->openLocation($def);
    }

    private function findReferences(): void
    {
        $sha = $this->ctx->sha();
        if ('' === $sha || !$this->ctx->codeIntel->has($this->ctx->name, $sha)) {
            $this->ctx->flash = 'No code intelligence index for this commit.';

            return;
        }
        $sym = $this->ctx->codeIntel->symbolAnchorAt($this->ctx->name, $sha, $this->filePath, $this->cursorLine, $this->cursorCol);
        $refs = $this->ctx->codeIntel->findReferences($this->ctx->name, $sha, $this->filePath, $this->cursorLine, $this->cursorCol, true);
        $byName = false;
        if ([] === $refs && null !== $sym) {
            // No exact references: propose references found by matching the symbol name.
            $refs = $this->ctx->codeIntel->findReferencesByName($this->ctx->name, $sha, $sym);
            $byName = [] !== $refs;
        }
        if ([] === $refs) {
            $this->ctx->flash = 'No references found at cursor.';

            return;
        }
        $this->refsTitle = sprintf('References for %s (%d%s)', $sym ?? 'symbol', \count($refs), $byName ? ', by name' : '');
        $this->refs = $refs;
        $this->refSel = 0;
        $this->refsOpen = true;
        $this->ensureRefPreview();
    }

    private function openLocation(SymbolLocation $loc): void
    {
        $flash = '→ ' . $loc->path . ':' . ($loc->line + 1);
        if ($loc->path !== $this->filePath) {
            $this->openFile($loc->path, true, $loc->line, $loc->col, $flash);

            return;
        }
        $this->cursorLine = max(0, min($loc->line, \count($this->fileRaw) - 1));
        $this->cursorCol = max(0, $loc->col);
        $this->ctx->flash = $flash;
    }

    private function ensureRefPreview(): void
    {
        if ([] === $this->refs || !isset($this->refs[$this->refSel])) {
            return;
        }
        $targetPath = $this->refs[$this->refSel]->path;
        if (isset($this->refsPreviewCache[$targetPath])) {
            return;
        }
        if ($targetPath === $this->filePath && [] !== $this->fileRaw) {
            $this->refsPreviewCache[$targetPath] = $this->fileRaw;

            return;
        }
        if (null !== $this->refsPreviewJob) {
            if ($this->refsPreviewJob['path'] === $targetPath && $this->refsPreviewJob['process']->isRunning()) {
                return;
            }
            if ($this->refsPreviewJob['process']->isRunning()) {
                $this->refsPreviewJob['process']->stop(0);
            }
        }
        try {
            $proc = $this->ctx->reader->startBlobProcess($this->ctx->git, $this->ctx->ref, $targetPath);
            $this->refsPreviewJob = [
                'path' => $targetPath,
                'process' => $proc,
            ];
        } catch (\Throwable) {
            $this->refsPreviewCache[$targetPath] = [];
        }
    }

    /**
     * Splits the references view width between the list (left) and the file
     * preview (right). A narrow terminal (< 60 cols) disables the preview pane.
     *
     * @return array{0: int, 1: int} [listWidth, previewWidth]
     */
    public function refsSplit(int $width): array
    {
        if ($width < 60) {
            return [$width, 0];
        }
        $listW = intdiv($width * 2, 5);
        if ($listW < 24) {
            $listW = 24;
        }

        return [$listW, $width - $listW - 1];
    }

    private function loadFile(string $path, string $content): void
    {
        $this->fileRaw = preg_split('/\r\n|\r|\n/', rtrim($content, "\n")) ?: [''];
        $this->filePath = $path;
        $this->filePreview = false;
        $this->filePreviewScroll = 0;
        $this->filePreviewCache = null;
        if (null !== $this->filePreviewJob && $this->filePreviewJob['process']->isRunning()) {
            $this->filePreviewJob['process']->stop(0);
        }
        $this->filePreviewJob = null;
        if (null !== $this->highlightJob && $this->highlightJob['process']->isRunning()) {
            $this->highlightJob['process']->stop(0);
        }
        $this->highlightJob = null;

        $highlighter = $this->highlighter ??= new CodeHighlighter();
        if (null !== CodeHighlighter::languageFor($path) && \count($this->fileRaw) > 150) {
            $this->highlighted = null;
            try {
                $this->highlightJob = [
                    'path' => $path,
                    'process' => $highlighter->startHighlightProcess($path, $content),
                ];
            } catch (\Throwable) {
                $lines = $highlighter->highlightLines($path, $content);
                $this->highlighted = null !== $lines && \count($lines) === \count($this->fileRaw) ? $lines : null;
            }
        } else {
            $lines = $highlighter->highlightLines($path, $content);
            $this->highlighted = null !== $lines && \count($lines) === \count($this->fileRaw) ? $lines : null;
        }
    }

    private function currentLineLength(): int
    {
        $line = $this->fileRaw[$this->cursorLine] ?? '';

        return mb_strlen($line);
    }

    private function parentPath(string $path): string
    {
        $pos = strrpos($path, '/');

        return false === $pos ? '' : substr($path, 0, $pos);
    }

    private function checkCanWrite(): bool
    {
        if (!$this->ctx->canWrite()) {
            $this->ctx->flash = 'Write access required.';

            return false;
        }
        if (!$this->ctx->currentRefIsBranch()) {
            $this->ctx->flash = 'Cannot commit: ref is not a branch.';

            return false;
        }

        return true;
    }

    private function startEditing(): void
    {
        $this->editing = true;
        $this->isNewFile = false;
        $this->editPath = $this->filePath;
        $this->editor->setContent(implode("\n", $this->fileRaw));
        $this->editor->enableMarkdownPreview($this->ctx->renderer, $this->editPath);
        $this->editor->moveTo($this->cursorLine, $this->cursorCol);
        $this->editorHighlighted = $this->highlighted;
        $this->editorDirty = false;
        if (null !== CodeHighlighter::languageFor($this->editPath)) {
            if (null !== $this->editorHighlightJob && $this->editorHighlightJob['process']->isRunning()) {
                $this->editorHighlightJob['process']->stop(0);
            }
            $this->editorHighlighted = null;
            try {
                $this->editorHighlightJob = [
                    'path' => $this->editPath,
                    'process' => ($this->highlighter ??= new CodeHighlighter())->startHighlightProcess($this->editPath, $this->editor->value()),
                ];
            } catch (\Throwable) {
                $this->editorHighlighted = $this->highlighted;
                $this->editorDirty = false;
            }
        }
    }

    private function isCtrlS(string $seq): bool
    {
        return Editor::isCtrlS($seq);
    }

    private function cleanPath(string $raw): string
    {
        $raw = trim($raw);
        $raw = str_replace('\\', '/', $raw);
        $raw = ltrim($raw, '/');
        $parts = [];
        foreach (explode('/', $raw) as $part) {
            if ('' === $part || '.' === $part) {
                continue;
            }
            if ('..' === $part) {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    private function handleEditorKey(string $seq): bool
    {
        if ($this->isCtrlS($seq)) {
            $this->commitPrompt = true;
            $this->commitAction = $this->isNewFile ? 'create' : 'update';
            $this->commitForm->reset(($this->isNewFile ? 'Create ' : 'Update ') . $this->editPath);

            return true;
        }

        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if (Keys::ESCAPE === $tok) {
            $this->editing = false;
            $this->editor->stopJobs();
            if (null !== $this->editorHighlightJob && $this->editorHighlightJob['process']->isRunning()) {
                $this->editorHighlightJob['process']->stop(0);
            }
            $this->editorHighlightJob = null;
            if ($this->isNewFile) {
                $this->isNewFile = false;
            }

            return true;
        }
        $handled = $this->editor->handleKey($seq);
        if ($handled && $this->editor->isDirty()) {
            $this->editorDirty = true;
        }

        return $handled;
    }

    private function handleNewFilePromptKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if (Keys::ESCAPE === $tok) {
            $this->newFilePrompt = false;

            return true;
        }

        if (Keys::ENTER === $tok) {
            $raw = trim($this->newFilePathInput);
            if ('' === $raw) {
                return true;
            }
            if ('' !== $this->ctx->subpath && !str_starts_with($raw, '/') && !str_starts_with($raw, $this->ctx->subpath . '/')) {
                $raw = $this->ctx->subpath . '/' . $raw;
            }
            $clean = $this->cleanPath($raw);
            if ('' === $clean) {
                return true;
            }

            $this->newFilePrompt = false;
            $this->editing = true;
            $this->isNewFile = true;
            $this->editPath = $clean;
            $this->editor->setContent('');
            $this->editor->enableMarkdownPreview($this->ctx->renderer, $this->editPath);
            $this->editorHighlighted = null;
            $this->editorDirty = true;

            return true;
        }

        if (Keys::BACKSPACE === $tok) {
            $this->newFilePathInput = mb_substr($this->newFilePathInput, 0, -1);

            return true;
        }

        if (null !== $txt) {
            $this->newFilePathInput .= $txt;

            return true;
        }

        return true;
    }

    private function handleConfirmDeleteKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if (Keys::ESCAPE === $tok || 'n' === $txt || 'N' === $txt) {
            $this->confirmDelete = false;

            return true;
        }

        if ('y' === $txt || 'Y' === $txt) {
            $path = $this->deleteTargetPath;
            $message = 'Delete ' . $path;
            $this->executeCommit('delete', $path, $message);

            return true;
        }

        return true;
    }

    private function handleCommitPromptKey(string $seq): bool
    {
        if ($this->isCtrlS($seq)) {
            $this->submitCommit();

            return true;
        }

        if (Keys::ESCAPE === Keys::normalize($seq)) {
            $this->commitPrompt = false;

            return true;
        }

        return $this->commitForm->handleKey($seq);
    }

    private function submitCommit(): void
    {
        $summary = trim($this->commitForm->summary());
        if ('' === $summary) {
            $this->ctx->flash = 'Commit summary is required.';

            return;
        }

        $fullMessage = $this->commitForm->message();
        $path = $this->editPath;
        $content = $this->editor->value();

        $this->executeCommit($this->commitAction, $path, $fullMessage, $content);
    }

    private function executeCommit(string $action, string $path, string $message, string $content = ''): bool
    {
        if (null === $this->ctx->db) {
            $this->ctx->flash = 'Repository not registered.';

            return false;
        }

        try {
            if ('delete' === $action) {
                if (null === $this->ctx->deleteFile) {
                    $this->ctx->flash = 'Delete not available.';

                    return false;
                }
                $sha = ($this->ctx->deleteFile)(
                    $this->ctx->actor,
                    $this->ctx->db->name(),
                    $this->ctx->ref,
                    $path,
                    $message,
                );
            } else {
                if (null === $this->ctx->writeFile) {
                    $this->ctx->flash = 'Write not available.';

                    return false;
                }
                $sha = ($this->ctx->writeFile)(
                    $this->ctx->actor,
                    $this->ctx->db->name(),
                    $this->ctx->ref,
                    $path,
                    $content,
                    $message,
                );
            }
        } catch (\Throwable $e) {
            $this->ctx->flash = 'Commit failed: ' . $e->getMessage();

            return false;
        }

        $this->ctx->invalidateGitCaches();
        $shortSha = substr($sha, 0, 7);
        $this->ctx->flash = 'Committed ' . $shortSha;

        $this->commitPrompt = false;
        $this->confirmDelete = false;
        $this->editing = false;
        $this->isNewFile = false;
        $this->newFilePrompt = false;

        if ('delete' === $action) {
            $this->fileOpen = false;
            $this->sel = max(0, min($this->sel, max(0, \count($this->ctx->tree()) - 1)));
        } else {
            $this->openFile($path, false, $this->editor->line(), $this->editor->col());
        }

        return true;
    }

    public function onClick(int $line, int $col, bool $nav = false): bool
    {
        if ($nav) {
            return $this->navigateSymbolClick($line, $col);
        }
        if (!isset($this->rowToIndex[$line])) {
            return false;
        }
        $idx = $this->rowToIndex[$line];

        if ($this->fuzzyOpen) {
            $matches = $this->fuzzyMatches();
            if (!isset($matches[$idx])) {
                return false;
            }
            if ($idx === $this->fuzzySel) {
                $entry = $matches[$idx]['entry'];
                $this->closeFuzzySearch();
                $this->openFile($entry->path);
            } else {
                $this->fuzzySel = $idx;
            }

            return true;
        }

        $entries = $this->ctx->tree();
        if (!isset($entries[$idx])) {
            return false;
        }
        if ($idx === $this->sel) {
            $entry = $entries[$idx];
            if ($entry->isDirectory()) {
                $this->ctx->subpath = $entry->path;
                $this->sel = 0;
            } else {
                $this->openFile($entry->path);
            }
        } else {
            $this->sel = $idx;
        }

        return true;
    }
    // --- rendering ---------------------------------------------------------

    public function paintBody(int $cols, int $rows): array
    {
        $this->cols = max(1, $cols);
        $this->rows = max(1, $rows);
        $this->rowToIndex = [];
        if ($this->commitPrompt) {
            return $this->paintCommitPrompt($cols, $rows);
        }
        if ($this->confirmDelete) {
            return $this->paintConfirmDelete($cols, $rows);
        }
        if ($this->newFilePrompt) {
            return $this->paintNewFilePrompt($cols, $rows);
        }
        if ($this->editing) {
            return $this->paintEditor($cols, $rows);
        }
        if ($this->refsOpen) {
            return $this->paintRefs($cols, $rows);
        }
        if ($this->fileOpen) {
            return $this->paintFile($cols, $rows);
        }
        if ($this->fuzzyOpen) {
            return $this->paintFuzzy($cols, $rows);
        }

        return $this->paintFiles($cols, $rows);
    }

    /**
     * @return list<string>
     */
    private function paintFiles(int $cols, int $rows): array
    {
        $crumb = '  /' . $this->ctx->subpath;
        $header = "\e[2m" . Screen::fit($crumb, $cols) . "\e[0m";
        $bodyRows = max(1, $rows - 1);

        if ($this->ctx->isTreeLoading()) {
            return array_merge([$header], Screen::spinnerBody($this->ctx->spinner, 'Loading files...', $cols));
        }

        $entries = $this->ctx->tree();
        if ([] === $entries) {
            return array_merge([$header], $this->messageBody('Empty directory.', $cols));
        }

        $count = \count($entries);
        $sel = min($this->sel, $count - 1);
        $top = $count > $bodyRows ? max(0, min($sel - intdiv($bodyRows, 2), $count - $bodyRows)) : 0;

        $out = [$header];
        for ($r = 0; $r < $bodyRows; ++$r) {
            $idx = $top + $r;
            if ($idx >= $count) {
                $out[] = Screen::blank($cols);
                continue;
            }
            $this->rowToIndex[1 + $r] = $idx;
            $entry = $entries[$idx];
            $size = $entry->isFile() && null !== $entry->size ? $this->humanSize($entry->size) : '';
            $out = array_merge($out, Screen::fileRow(
                $this->modeString($entry),
                $size,
                $entry->name,
                $entry->isDirectory(),
                $idx === $sel,
                $cols,
            ));
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function paintFuzzy(int $cols, int $rows): array
    {
        $header = Screen::fuzzySearchBar($this->fuzzyQuery, $cols);
        $bodyRows = max(1, $rows - 1);

        if ($this->ctx->isRecursiveTreeLoading()) {
            return array_merge([$header], Screen::spinnerBody($this->ctx->spinner, 'Loading files...', $cols));
        }

        $matches = $this->fuzzyMatches();
        if ([] === $matches) {
            return array_merge([$header], $this->messageBody('No matching files.', $cols));
        }

        $count = \count($matches);
        $sel = min($this->fuzzySel, $count - 1);
        $top = $count > $bodyRows ? max(0, min($sel - intdiv($bodyRows, 2), $count - $bodyRows)) : 0;

        $out = [$header];
        for ($r = 0; $r < $bodyRows; ++$r) {
            $idx = $top + $r;
            if ($idx >= $count) {
                $out[] = Screen::blank($cols);
                continue;
            }
            $this->rowToIndex[1 + $r] = $idx;
            $match = $matches[$idx];
            $entry = $match['entry'];
            $selected = $idx === $sel;
            $size = $entry->isFile() && null !== $entry->size ? $this->humanSize($entry->size) : '';
            $renderedPath = $this->highlightFuzzyPath($entry->path, $match['positions'], $selected);
            $out = array_merge($out, Screen::fileRow(
                $this->modeString($entry),
                $size,
                $entry->path,
                false,
                $selected,
                $cols,
                $renderedPath,
            ));
        }

        return $out;
    }

    /**
     * @param list<int> $positions
     */
    private function highlightFuzzyPath(string $path, array $positions, bool $selected): string
    {
        if ([] === $positions) {
            return $selected
                ? Theme::style($path, Theme::SELECTOR, bold: true)
                : Theme::style($path, Theme::FG);
        }

        $chars = preg_split('//u', $path, -1, \PREG_SPLIT_NO_EMPTY);
        if (false === $chars || [] === $chars) {
            return $path;
        }

        $posSet = array_fill_keys($positions, true);
        $out = '';
        $baseColor = $selected ? Theme::SELECTOR : Theme::FG;
        $matchColor = $selected ? Theme::CYAN : Theme::HIGHLIGHT;

        foreach ($chars as $i => $ch) {
            if (isset($posSet[$i])) {
                $out .= Theme::style($ch, $matchColor, bold: true, underline: true);
            } else {
                $out .= Theme::style($ch, $baseColor, bold: $selected);
            }
        }

        return $out;
    }

    public function statusInfo(): string
    {
        if ($this->fuzzyOpen) {
            if ($this->ctx->isRecursiveTreeLoading()) {
                return '…';
            }
            $total = \count($this->ctx->recursiveTree());
            $matches = $this->fuzzyMatches();
            $count = \count($matches);
            if (0 === $count) {
                return '0/' . $total;
            }

            return '# ' . (min($this->fuzzySel, $count - 1) + 1) . '/' . $count;
        }
        if ($this->ctx->isTreeLoading()) {
            return '…';
        }
        $count = \count($this->ctx->tree());
        if (0 === $count) {
            return '';
        }

        return '# ' . (min($this->sel, $count - 1) + 1) . '/' . $count;
    }

    private function modeString(TreeEntry $entry): string
    {
        $oct = (int) octdec($entry->mode);
        $perm = $oct & 0o777;
        if ($entry->isDirectory()) {
            $type = 'd';
            $perm = 0 !== $perm ? $perm : 0o777;
        } elseif (0o120000 === ($oct & 0o170000)) {
            $type = 'l';
        } else {
            $type = '-';
        }
        $rwx = '';
        foreach ([6, 3, 0] as $shift) {
            $p = ($perm >> $shift) & 7;
            $rwx .= (0 !== ($p & 4) ? 'r' : '-') . (0 !== ($p & 2) ? 'w' : '-') . (0 !== ($p & 1) ? 'x' : '-');
        }

        return $type . $rwx;
    }

    /**
     * @return list<string>
     */
    private function paintFile(int $cols, int $rows): array
    {
        if (null !== $this->blobJob) {
            $crumb = "\e[2m" . Screen::fit('  ' . $this->filePath, $cols) . "\e[0m";

            return array_merge([$crumb], Screen::spinnerBody($this->ctx->spinner, 'Loading ' . $this->filePath . '...', $cols));
        }
        if (null !== $this->highlightJob) {
            $crumb = "\e[2m" . Screen::fit('  ' . $this->filePath, $cols) . "\e[0m";

            return array_merge([$crumb], Screen::spinnerBody($this->ctx->spinner, 'Highlighting ' . $this->filePath . '...', $cols));
        }

        if ($this->filePreview) {
            return $this->paintFilePreview($cols, $rows);
        }
        $crumb = "\e[2m" . Screen::fit('  ' . $this->filePath, $cols) . "\e[0m";
        $rows = max(1, $rows - 1);
        $count = \count($this->fileRaw);
        $gutter = max(3, \strlen((string) $count));
        $codeWidth = max(1, $cols - $gutter - 2);
        $top = $count > $rows ? max(0, min($this->cursorLine - intdiv($rows, 2), $count - $rows)) : 0;
        $this->fileTop = $top;
        $this->fileGutter = $gutter;

        $rowsOut = [$crumb];
        for ($r = 0; $r < $rows; ++$r) {
            $idx = $top + $r;
            if ($idx >= $count) {
                $rowsOut[] = Screen::blank($cols);
                continue;
            }

            $code = $this->highlighted[$idx] ?? $this->fileRaw[$idx];
            $num = str_pad((string) ($idx + 1), $gutter, ' ', \STR_PAD_LEFT);
            if ($idx === $this->cursorLine) {
                $g = "\e[7m" . $num . '▶' . "\e[0m" . ' ';
            } else {
                $g = "\e[2m" . $num . '│' . "\e[0m" . ' ';
            }
            $rowsOut[] = Screen::fit($g . Screen::truncate($code, $codeWidth), $cols);
        }

        return $rowsOut;
    }

    /**
     * @return list<string>
     */
    private function paintFilePreview(int $cols, int $rows): array
    {
        $crumb = "\e[2m" . Screen::fit('  ' . $this->filePath, $cols) . "\e[0m";
        $bodyRows = max(1, $rows - 1);
        if ($this->isFilePreviewLoading($cols)) {
            return array_merge([$crumb], Screen::spinnerBody($this->ctx->spinner, 'Rendering preview...', $cols));
        }
        $lines = $this->filePreviewLines($cols);
        $total = \count($lines);
        $maxScroll = max(0, $total - $bodyRows);
        $this->filePreviewScroll = max(0, min($this->filePreviewScroll, $maxScroll));

        $rowsOut = [$crumb];
        for ($r = 0; $r < $bodyRows; ++$r) {
            $idx = $this->filePreviewScroll + $r;
            $rowsOut[] = $idx < $total ? Screen::fit($lines[$idx], $cols) : Screen::blank($cols);
        }

        return $rowsOut;
    }

    private function isFilePreviewLoading(int $cols): bool
    {
        $key = $this->filePath . ':' . $cols;
        if (null !== $this->filePreviewCache && $this->filePreviewCache['key'] === $key) {
            return false;
        }
        if (!$this->ctx->renderer->isAvailable()) {
            $this->filePreviewCache = ['key' => $key, 'lines' => $this->fileRaw];

            return false;
        }
        if (null === $this->filePreviewJob || $this->filePreviewJob['key'] !== $key) {
            if (null !== $this->filePreviewJob && $this->filePreviewJob['process']->isRunning()) {
                $this->filePreviewJob['process']->stop(0);
            }
            try {
                $this->filePreviewJob = [
                    'key' => $key,
                    'process' => $this->ctx->renderer->startRenderDocumentProcess($this->filePath, implode("\n", $this->fileRaw), max(20, $cols - 2)),
                ];
            } catch (\Throwable) {
                $this->filePreviewCache = ['key' => $key, 'lines' => $this->fileRaw];

                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function filePreviewLines(int $cols): array
    {
        $key = $this->filePath . ':' . $cols;
        if (null === $this->filePreviewCache || $this->filePreviewCache['key'] !== $key) {
            $content = implode("\n", $this->fileRaw);
            if ($this->ctx->renderer->isAvailable()) {
                try {
                    $ansi = $this->ctx->renderer->renderDocument($this->filePath, $content, max(20, $cols - 2));
                    $lines = preg_split('/\r\n|\r|\n/', rtrim($ansi, "\n")) ?: [];
                } catch (\Throwable) {
                    $lines = $this->fileRaw;
                }
            } else {
                $lines = $this->fileRaw;
            }
            $this->filePreviewCache = ['key' => $key, 'lines' => $lines];
        }

        return $this->filePreviewCache['lines'];
    }

    private function isMarkdownFile(): bool
    {
        if ('markdown' === CodeHighlighter::languageFor($this->filePath)) {
            return true;
        }
        $ext = strtolower(pathinfo($this->filePath, \PATHINFO_EXTENSION));

        return 'md' === $ext || 'markdown' === $ext;
    }

    /**
     * @return list<string>
     */
    private function paintRefs(int $cols, int $rows): array
    {
        [$listW, $previewW] = $this->refsSplit($cols);
        $bodyRows = max(1, $rows - 1);

        $items = [];
        foreach ($this->refs as $loc) {
            $items[] = ['label' => $loc->path, 'meta' => ($loc->line + 1) . ':' . ($loc->col + 1)];
        }
        $listHeader = "\e[2m" . Screen::fit('  ' . \count($this->refs) . ' reference(s)', $listW) . "\e[0m";
        $listLines = array_merge([$listHeader], Screen::listView($items, $this->refSel, $bodyRows, $listW));

        if ($previewW <= 0) {
            return $listLines;
        }

        $previewLines = $this->paintRefPreview($previewW, $bodyRows);

        $joined = [];
        $total = max(\count($listLines), \count($previewLines));
        for ($i = 0; $i < $total; ++$i) {
            $left = $listLines[$i] ?? Screen::blank($listW);
            $right = $previewLines[$i] ?? Screen::blank($previewW);
            $joined[] = Screen::fit($left, $listW) . "\e[2m│\e[0m" . $right;
        }

        return $joined;
    }

    /**
     * Renders the file-content preview pane for the selected reference,
     * centered on the referenced line. Returns $bodyRows + 1 lines (a header
     * plus body) to align with the list pane.
     *
     * @return list<string>
     */
    private function paintRefPreview(int $previewW, int $bodyRows): array
    {
        $loc = $this->refs[$this->refSel] ?? null;
        if (null === $loc) {
            $out = [Screen::blank($previewW)];
            for ($r = 0; $r < $bodyRows; ++$r) {
                $out[] = Screen::blank($previewW);
            }

            return $out;
        }

        $header = "\e[2m" . Screen::fit('  ' . $loc->path . ':' . ($loc->line + 1), $previewW) . "\e[0m";
        $rawLines = $this->refsPreviewCache[$loc->path] ?? null;
        if (null === $rawLines && $loc->path === $this->filePath) {
            $rawLines = $this->fileRaw;
        }

        $body = [];
        if (null === $rawLines) {
            $spin = Screen::spinnerBody($this->ctx->spinner, 'Loading preview...', $previewW);
            for ($r = 0; $r < $bodyRows; ++$r) {
                $body[] = $spin[$r] ?? Screen::blank($previewW);
            }
        } elseif ([] === $rawLines) {
            for ($r = 0; $r < $bodyRows; ++$r) {
                $body[] = 0 === $r ? Screen::fit('  (empty or unreadable)', $previewW) : Screen::blank($previewW);
            }
        } else {
            $count = \count($rawLines);
            $gutter = max(3, \strlen((string) $count));
            $codeWidth = max(1, $previewW - $gutter - 2);
            $top = $count > $bodyRows ? max(0, min($loc->line - intdiv($bodyRows, 2), $count - $bodyRows)) : 0;
            for ($r = 0; $r < $bodyRows; ++$r) {
                $idx = $top + $r;
                if ($idx >= $count) {
                    $body[] = Screen::blank($previewW);
                    continue;
                }
                $num = str_pad((string) ($idx + 1), $gutter, ' ', \STR_PAD_LEFT);
                if ($idx === $loc->line) {
                    $g = "\e[7m" . $num . '▶' . "\e[0m" . ' ';
                } else {
                    $g = "\e[2m" . $num . '│' . "\e[0m" . ' ';
                }
                $body[] = Screen::fit($g . Screen::truncate($rawLines[$idx], $codeWidth), $previewW);
            }
        }

        return array_merge([$header], $body);
    }

    /**
     * @return list<string>
     */
    private function paintEditor(int $cols, int $rows): array
    {
        $tag = $this->isNewFile ? '[NEW]' : '[EDIT]';
        $label = $tag . ' ' . $this->editPath;

        if (null !== $this->editorHighlightJob) {
            $crumb = "\e[2m" . Screen::fit('  ' . $label, $cols) . "\e[0m";

            return array_merge([$crumb], Screen::spinnerBody($this->ctx->spinner, 'Preparing editor...', $cols));
        }

        if ($this->editorDirty) {
            $this->refreshEditorHighlighting();
        }

        return $this->editor->paint($label, $cols, $rows, $this->editorHighlighted);
    }

    private function refreshEditorHighlighting(): void
    {
        $this->editorDirty = false;
        $lines = ($this->highlighter ??= new CodeHighlighter())->highlightLines($this->editPath, $this->editor->value());
        if (null === $lines) {
            $this->editorHighlighted = null;

            return;
        }
        $count = \count($this->editor->lines());
        while (\count($lines) < $count) {
            $lines[] = '';
        }
        $this->editorHighlighted = \count($lines) === $count ? $lines : null;
    }

    /**
     * @return list<string>
     */
    private function paintNewFilePrompt(int $cols, int $rows): array
    {
        $title = 'New File';
        $boxWidth = min(60, max(40, $cols - 4));
        $dir = '' === $this->ctx->subpath ? '/' : '/' . $this->ctx->subpath;

        $lines = [
            '',
            '  ' . Theme::style('Directory: ', Theme::MUTED) . Theme::style($dir, Theme::FG),
            '',
            '  ' . Theme::style('File path:', Theme::BLUE, bold: true),
            '  > ' . $this->newFilePathInput . '▏',
            '',
            '  ' . Theme::style('Enter: open editor · Esc: cancel', Theme::MUTED, italic: true),
        ];

        $box = Screen::box($title, $lines, $boxWidth);

        return Screen::centerBlock($box, $cols, $rows);
    }

    /**
     * @return list<string>
     */
    private function paintConfirmDelete(int $cols, int $rows): array
    {
        $title = 'Delete file';
        $boxWidth = min(54, max(36, $cols - 4));

        $lines = [
            '',
            '  ' . Theme::style('Delete ' . $this->deleteTargetPath . '?', Theme::SELECTOR, bold: true),
            '',
            '  ' . Theme::style('y: confirm delete · n/Esc: cancel', Theme::MUTED, italic: true),
        ];

        $box = Screen::box($title, $lines, $boxWidth);

        return Screen::centerBlock($box, $cols, $rows);
    }

    /**
     * @return list<string>
     */
    private function paintCommitPrompt(int $cols, int $rows): array
    {
        $authorName = $this->ctx->actor?->username() ?? 'anonymous';
        $authorEmail = $authorName . '@noreply.codes.sh';

        return $this->commitForm->paint($cols, $rows, $authorName . ' <' . $authorEmail . '>', $this->editPath);
    }
}

<?php

declare(strict_types=1);

namespace App\UI\Tui\Page\Repo;

use App\CodeIntel\Domain\SymbolLocation;
use App\UI\Tui\Input\Keys;
use App\UI\Tui\Render\CodeHighlighter;
use App\UI\Tui\Render\CommitForm;
use App\UI\Tui\Render\InlineEditor;
use App\UI\Tui\Render\Screen;
use App\UI\Tui\Render\Theme;

/**
 * Files tab: a directory browser over the current ref, opening a
 * syntax-highlighted code viewer with keyboard go-to-definition ('d') and
 * find-references ('r') backed by {@see \App\CodeIntel\Application\Query\CodeIntelQuery}.
 */
final class FilesTab extends AbstractRepoTab
{
    private bool $fileOpen = false;
    private string $filePath = '';
    /** @var list<string> */
    private array $fileRaw = [];
    private int $cursorLine = 0;
    private int $cursorCol = 0;
    private bool $filePreview = false;
    private int $filePreviewScroll = 0;
    /** @var array{key: string, lines: list<string>}|null */
    private ?array $filePreviewCache = null;
    private int $cols = 80;

    /** @var list<string>|null ANSI-highlighted lines parallel to $fileRaw; null = plain text */
    private ?array $highlighted = null;
    private ?CodeHighlighter $highlighter = null;

    private bool $refsOpen = false;
    /** @var list<SymbolLocation> */
    private array $refs = [];
    private int $refSel = 0;

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

    private InlineEditor $editor;
    private CommitForm $commitForm;

    public function __construct(RepoContext $ctx)
    {
        parent::__construct($ctx);
        $this->editor = new InlineEditor();
        $this->commitForm = new CommitForm();
    }

    public function capturesInput(): bool
    {
        return $this->fileOpen
            || $this->refsOpen
            || $this->editing
            || $this->newFilePrompt
            || $this->confirmDelete
            || $this->commitPrompt;
    }

    public function onActivate(): void
    {
        parent::onActivate();
        $this->fileOpen = false;
        $this->refsOpen = false;
        $this->editing = false;
        $this->newFilePrompt = false;
        $this->confirmDelete = false;
        $this->commitPrompt = false;
        $this->filePreview = false;
        $this->filePreviewScroll = 0;
        $this->filePreviewCache = null;
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

        return ' n: new · D: delete · ' . self::BASE_FOOTER;
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

        return $this->handleTabKey(Keys::normalize($seq), Keys::text($seq));
    }

    private function handleTabKey(string $tok, ?string $txt): bool
    {
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
            $this->fileOpen = false;
            $this->filePreview = false;
            $this->filePreviewScroll = 0;

            return true;
        }

        if ('p' === $txt && $this->isMarkdownFile()) {
            $this->filePreview = !$this->filePreview;
            $this->filePreviewScroll = 0;

            return true;
        }

        if ($this->filePreview) {
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
        } elseif (Keys::DOWN === $tok || 'j' === $txt) {
            $this->refSel = max(0, min($this->refSel + 1, \count($this->refs) - 1));
        } elseif (Keys::ENTER === $tok && [] !== $this->refs) {
            $this->refsOpen = false;
            $this->openLocation($this->refs[min($this->refSel, \count($this->refs) - 1)]);
        } else {
            return false;
        }

        return true;
    }

    // --- actions -----------------------------------------------------------

    private function openFile(string $path): void
    {
        try {
            $content = $this->ctx->reader->getBlob($this->ctx->git, $this->ctx->ref, $path);
        } catch (\Throwable) {
            $this->ctx->flash = 'Cannot open ' . $path;

            return;
        }
        $this->loadFile($path, $content);
        $this->fileOpen = true;
        $this->cursorLine = 0;
        $this->cursorCol = 0;
    }

    private function gotoDefinition(): void
    {
        $sha = $this->ctx->sha();
        if ('' === $sha || !$this->ctx->codeIntel->has($this->ctx->name, $sha)) {
            $this->ctx->flash = 'No code intelligence index for this commit.';

            return;
        }
        $def = $this->ctx->codeIntel->findDefinition($this->ctx->name, $sha, $this->filePath, $this->cursorLine, $this->cursorCol);
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
        $refs = $this->ctx->codeIntel->findReferences($this->ctx->name, $sha, $this->filePath, $this->cursorLine, $this->cursorCol);
        if ([] === $refs) {
            $this->ctx->flash = 'No references found at cursor.';

            return;
        }
        $this->refs = $refs;
        $this->refSel = 0;
        $this->refsOpen = true;
    }

    private function openLocation(SymbolLocation $loc): void
    {
        if ($loc->path !== $this->filePath) {
            try {
                $content = $this->ctx->reader->getBlob($this->ctx->git, $this->ctx->ref, $loc->path);
            } catch (\Throwable) {
                $this->ctx->flash = 'Cannot open ' . $loc->path;

                return;
            }
            $this->loadFile($loc->path, $content);
            $this->fileOpen = true;
        }
        $this->cursorLine = max(0, min($loc->line, \count($this->fileRaw) - 1));
        $this->cursorCol = max(0, $loc->col);
        $this->ctx->flash = '→ ' . $loc->path . ':' . ($loc->line + 1);
    }

    private function loadFile(string $path, string $content): void
    {
        $this->fileRaw = preg_split('/\r\n|\r|\n/', rtrim($content, "\n")) ?: [''];
        $lines = ($this->highlighter ??= new CodeHighlighter())->highlightLines($path, $content);
        $this->highlighted = null !== $lines && \count($lines) === \count($this->fileRaw) ? $lines : null;
        $this->filePath = $path;
        $this->filePreview = false;
        $this->filePreviewScroll = 0;
        $this->filePreviewCache = null;
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
        $this->editorDirty = null === $this->highlighted;
    }

    private function isCtrlS(string $seq): bool
    {
        return InlineEditor::isCtrlS($seq);
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
        if ($this->isCtrlS($seq) || Keys::ENTER === Keys::normalize($seq)) {
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
            $this->openFile($path);
            $this->cursorLine = $this->editor->line();
            $this->cursorCol = $this->editor->col();
        }

        return true;
    }
    // --- rendering ---------------------------------------------------------

    public function paintBody(int $cols, int $rows): array
    {
        $this->cols = max(1, $cols);
        $this->rows = max(1, $rows);
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

        return $this->paintFiles($cols, $rows);
    }

    /**
     * @return list<string>
     */
    private function paintFiles(int $cols, int $rows): array
    {
        $entries = $this->ctx->tree();
        $crumb = '  /' . $this->ctx->subpath;
        $header = "\e[2m" . Screen::fit($crumb, $cols) . "\e[0m";
        $bodyRows = max(1, $rows - 1);

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

    public function statusInfo(): string
    {
        $count = \count($this->ctx->tree());
        if (0 === $count) {
            return '';
        }

        return '# ' . (min($this->sel, $count - 1) + 1) . '/' . $count;
    }

    private function modeString(\App\Repo\Domain\Git\TreeEntry $entry): string
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
        if ($this->filePreview) {
            return $this->paintFilePreview($cols, $rows);
        }

        $crumb = "\e[2m" . Screen::fit('  ' . $this->filePath, $cols) . "\e[0m";
        $rows = max(1, $rows - 1);
        $count = \count($this->fileRaw);
        $gutter = max(3, \strlen((string) $count));
        $codeWidth = max(1, $cols - $gutter - 2);
        $top = $count > $rows ? max(0, min($this->cursorLine - intdiv($rows, 2), $count - $rows)) : 0;

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
        if (CodeHighlighter::languageFor($this->filePath) === 'markdown') {
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
        $items = [];
        foreach ($this->refs as $loc) {
            $items[] = ['label' => $loc->path, 'meta' => ($loc->line + 1) . ':' . ($loc->col + 1)];
        }
        $header = "\e[2m" . Screen::fit('  ' . \count($this->refs) . ' reference(s)', $cols) . "\e[0m";

        return array_merge([$header], Screen::listView($items, $this->refSel, max(1, $rows - 1), $cols));
    }

    /**
     * @return list<string>
     */
    private function paintEditor(int $cols, int $rows): array
    {
        $tag = $this->isNewFile ? '[NEW]' : '[EDIT]';
        $label = $tag . ' ' . $this->editPath;

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

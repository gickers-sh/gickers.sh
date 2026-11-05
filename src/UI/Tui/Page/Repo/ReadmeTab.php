<?php

declare(strict_types=1);

namespace App\UI\Tui\Page\Repo;

use App\UI\Tui\Input\Keys;
use App\UI\Tui\Render\CommitForm;
use App\UI\Tui\Render\InlineEditor;

/**
 * Readme tab: scrollable rendered README (Termstrap ANSI when available),
 * with inline editing via shortcut 'e'.
 */
final class ReadmeTab extends AbstractRepoTab
{
    /** Rendered line count from the last paint, at the real terminal width. */
    private int $lineCount = 0;

    private bool $editing = false;
    private bool $commitPrompt = false;
    private string $readmePath = 'README.md';
    private bool $isNew = false;

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
        return $this->editing || $this->commitPrompt;
    }

    public function onActivate(): void
    {
        parent::onActivate();
        $this->editing = false;
        $this->commitPrompt = false;
        $this->isNew = false;
    }

    public function footerText(): string
    {
        if ($this->commitPrompt) {
            return ' Tab/↑/↓: field · Enter/Ctrl+S: commit · Esc: cancel';
        }
        if ($this->editing) {
            $hint = $this->editor->previewSupported()
                ? ('Ctrl+P: ' . $this->editor->previewMode())
                : null;

            return sprintf(
                ' Ln %d, Col %d · %sCtrl+S: save · Esc: discard',
                $this->editor->line() + 1,
                $this->editor->col() + 1,
                null !== $hint ? $hint . ' · ' : '',
            );
        }

        return ' e: edit · ' . ltrim(self::BASE_FOOTER);
    }

    public function statusValue(): string
    {
        if ($this->commitPrompt) {
            return '[COMMIT]';
        }
        if ($this->editing) {
            return $this->isNew ? '[NEW]' : '[EDIT]';
        }

        return '';
    }

    public function statusInfo(): string
    {
        if ($this->editing) {
            return sprintf('Ln %d, Col %d', $this->editor->line() + 1, $this->editor->col() + 1);
        }
        if ($this->commitPrompt) {
            return '';
        }

        $lineCount = $this->lineCount > 0 ? $this->lineCount : $this->ctx->readmeLineCount();
        if (0 === $lineCount) {
            return '☰ 0%';
        }
        $maxScroll = max(0, $lineCount - $this->rows);
        $percent = $maxScroll > 0 ? (int) round(min($this->scroll, $maxScroll) / $maxScroll * 100) : 100;

        return sprintf('☰ %d%%', $percent);
    }

    public function handleKey(string $seq): bool
    {
        if ($this->commitPrompt) {
            return $this->handleCommitPromptKey($seq);
        }

        if ($this->editing) {
            return $this->handleEditorKey($seq);
        }

        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if ('e' === $txt) {
            return $this->startEditing();
        }

        $lineCount = $this->lineCount > 0 ? $this->lineCount : $this->ctx->readmeLineCount();

        return $this->handleDocScroll($tok, $txt, $lineCount);
    }

    private function startEditing(): bool
    {
        if (!$this->ctx->canWrite()) {
            $this->ctx->flash = 'Write access required.';

            return true;
        }

        if (!$this->ctx->currentRefIsBranch()) {
            $this->ctx->flash = 'Cannot commit: ref is not a branch.';

            return true;
        }

        $readme = $this->ctx->reader->readme($this->ctx->git, $this->ctx->ref);
        if (null !== $readme) {
            $this->readmePath = $readme['path'];
            $this->isNew = false;
            $this->editor->setContent($readme['content']);
        } else {
            $this->readmePath = 'README.md';
            $this->isNew = true;
            $this->editor->setContent('');
        }

        $this->editor->enableMarkdownPreview($this->ctx->renderer, $this->readmePath);
        $this->editing = true;

        return true;
    }

    private function handleEditorKey(string $seq): bool
    {
        if (InlineEditor::isCtrlS($seq)) {
            $this->commitPrompt = true;
            $this->commitForm->reset($this->isNew ? 'Create README' : 'Update README');

            return true;
        }

        $tok = Keys::normalize($seq);

        if (Keys::ESCAPE === $tok) {
            $this->editing = false;
            $this->isNew = false;

            return true;
        }

        return $this->editor->handleKey($seq);
    }

    private function handleCommitPromptKey(string $seq): bool
    {
        if (InlineEditor::isCtrlS($seq) || Keys::ENTER === Keys::normalize($seq)) {
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

        if (null === $this->ctx->db) {
            $this->ctx->flash = 'Repository not registered.';

            return;
        }

        if (null === $this->ctx->writeFile) {
            $this->ctx->flash = 'Write operations unavailable.';

            return;
        }

        try {
            $sha = ($this->ctx->writeFile)(
                $this->ctx->actor,
                $this->ctx->db->name(),
                $this->ctx->ref,
                $this->readmePath,
                $this->editor->value(),
                $this->commitForm->message(),
            );
        } catch (\Throwable $e) {
            $this->ctx->flash = 'Commit failed: ' . $e->getMessage();

            return;
        }

        $this->ctx->invalidateGitCaches();
        $this->ctx->flash = 'Committed ' . substr($sha, 0, 7);

        $this->commitPrompt = false;
        $this->editing = false;
        $this->isNew = false;
    }

    public function paintBody(int $cols, int $rows): array
    {
        if ($this->commitPrompt) {
            $authorName = $this->ctx->actor?->username() ?? 'anonymous';
            $authorEmail = $authorName . '@noreply.codes.sh';

            return $this->commitForm->paint($cols, $rows, $authorName . ' <' . $authorEmail . '>', $this->readmePath);
        }

        if ($this->editing) {
            $tag = $this->isNew ? '[NEW]' : '[EDIT]';

            return $this->editor->paint($tag . ' ' . $this->readmePath, $cols, $rows);
        }

        $lines = $this->ctx->readmeLines($cols);
        $this->lineCount = \count($lines);
        if ([] === $lines) {
            return $this->messageBody('This repository has no README.', $cols);
        }

        return $this->docWindow($lines, $cols, $rows);
    }
}

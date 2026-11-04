<?php

declare(strict_types=1);

namespace App\Tui\Page;

use App\Model\CommitModel;
use App\Model\GitRepositoryModel;
use App\Model\PullRequestModel;
use App\Model\UserModel;
use App\Service\Backend\BackendService;
use App\Service\CodeIntel\CodeIntelService;
use App\Service\Git\GitPlumbingService;
use App\Service\Git\GitService;
use App\Service\Render\TermstrapRenderer;
use App\Tui\Component\CodeViewerComponent;
use App\Tui\Component\ComponentInterface;
use App\Tui\Component\EditorComponent;
use App\Tui\Component\Field\TextAreaField;
use App\Tui\Component\Field\TextInputField;
use App\Tui\Component\Field\ToggleField;
use App\Tui\Component\FormComponent;
use App\Tui\Component\HeaderComponent;
use App\Tui\Component\SelectorComponent;
use App\Tui\Component\StatusbarComponent;
use App\Tui\Component\TabsComponent;
use App\Tui\Component\ViewportComponent;
use App\Tui\Event\InputEvent;
use App\Tui\Event\KeyMsg;
use App\Tui\Event\MouseMsg;
use App\Tui\Style\Ansi;
use App\Tui\Style\Layout;
use App\Tui\Style\Style;
use App\Tui\Style\TokyoNight;
use Throwable;

final class RepoPage implements ComponentInterface
{
    public const TAB_README = 0;
    public const TAB_FILES = 1;
    public const TAB_LOG = 2;
    public const TAB_BRANCHES = 3;
    public const TAB_TAGS = 4;
    public const TAB_PRS = 5;
    public const TAB_PROFILE = 6;
    public const TAB_SETTINGS = 7;

    private TabsComponent $tabs;
    private HeaderComponent $header;
    private StatusbarComponent $statusbar;

    // Readme tab
    private ViewportComponent $readmeViewport;
    private ?string $readmePath = null;
    private string $readmeContent = '';

    // Files tab
    private SelectorComponent $filesSelector;
    private CodeViewerComponent $fileViewer;
    private ViewportComponent $renderedDocViewport;
    private ViewportComponent $blameViewport;
    private ViewportComponent $refsPreviewViewport;
    private bool $viewingFile = false;
    private bool $viewingBlame = false;
    private bool $viewingRefsPreview = false;
    private string $currentFilePath = '';
    private string $currentSubpath = '';
    private bool $isFileRendered = false;

    // Log tab
    private SelectorComponent $logSelector;
    private ViewportComponent $commitDiffViewport;
    private bool $viewingCommitDiff = false;
    private ?CommitModel $currentCommit = null;

    // Other tabs
    private SelectorComponent $branchesSelector;
    private SelectorComponent $tagsSelector;
    private SelectorComponent $prsSelector;
    private ViewportComponent $profileViewport;
    private FormComponent $settingsForm;

    // Pull Requests Detail view
    private bool $viewingPRDetail = false;
    private ?PullRequestModel $selectedPR = null;
    private ViewportComponent $prDetailViewport;
    private int $prDetailTab = 0; // 0=About, 1=Commits, 2=Changes, 3=Comments
    private bool $prCommentMenuActive = false;
    private ?FormComponent $prFormModal = null;
    private ?FormComponent $prCommentModal = null;
    private ?FormComponent $prConfirmDeleteModal = null;

    // Optional modal editor/form
    private ?EditorComponent $activeEditor = null;
    private ?FormComponent $activeModal = null;
    private ?FormComponent $commitModal = null;
    private ?string $editingTargetFile = null;
    private bool $isEditingReadme = false;
    private bool $showHelp = false;

    private string $currentRef = 'HEAD';
    /** @var callable|null */
    private $onBackCallback = null;
    private GitPlumbingService $plumbing;

    public function __construct(
        private readonly GitRepositoryModel $repo,
        private readonly GitService $gitService,
        private readonly TermstrapRenderer $renderer,
        private readonly ?BackendService $backend = null,
        private readonly ?UserModel $currentUser = null,
        private readonly ?CodeIntelService $codeIntel = null,
    ) {
        $this->plumbing = new GitPlumbingService();

        $this->header = new HeaderComponent($this->repo->name, [
            $this->repo->defaultBranch,
            $this->repo->isPrivate ? 'private' : 'public',
        ]);

        $this->tabs = new TabsComponent([
            'Readme',
            'Files',
            'Log',
            'Branches',
            'Tags',
            'Pull Requests',
            'Profil',
            'Settings',
        ]);

        $this->statusbar = new StatusbarComponent();
        $this->readmeViewport = new ViewportComponent();
        $this->filesSelector = new SelectorComponent([], 'Files');
        $this->filesSelector->setDisplayMode('files');
        $this->fileViewer = new CodeViewerComponent();
        $this->renderedDocViewport = new ViewportComponent();
        $this->blameViewport = new ViewportComponent();
        $this->refsPreviewViewport = new ViewportComponent();
        $this->logSelector = new SelectorComponent([], 'Commits');
        $this->commitDiffViewport = new ViewportComponent();
        $this->branchesSelector = new SelectorComponent([], 'Branches');
        $this->tagsSelector = new SelectorComponent([], 'Tags');
        $this->prsSelector = new SelectorComponent([], 'Pull Requests');
        $this->profileViewport = new ViewportComponent();
        $this->prDetailViewport = new ViewportComponent();

        $this->initSettingsForm();
    }

    public function setOnBack(?callable $callback): void
    {
        $this->onBackCallback = $callback;
    }

    public function init(): void
    {
        $this->currentRef = $this->repo->defaultBranch ?: 'HEAD';
        $this->tabs->setActiveTab(self::TAB_README);
        $this->loadReadme();
        $this->loadFiles('');
        $this->loadCommits();
        $this->loadBranchesAndTags();
        $this->loadPRs();
        $this->loadProfile();
        $this->updateStatusbar();
    }

    private function initSettingsForm(): void
    {
        $descField = new TextInputField('description', 'Description', $this->repo->description);
        $privField = new ToggleField('is_private', 'Private repository', $this->repo->isPrivate);
        $this->settingsForm = new FormComponent('Repository Settings', 'Configure repository metadata', [
            $descField,
            $privField,
        ]);
    }

    private function loadReadme(): void
    {
        try {
            $entries = $this->gitService->getTree($this->repo, $this->currentRef);
            $this->readmePath = null;
            $this->readmeContent = '';
            foreach ($entries as $entry) {
                if ($entry->isFile() && preg_match('/^readme(\.md|\.markdown|\.txt)?$/i', $entry->name)) {
                    $this->readmePath = $entry->path;
                    break;
                }
            }

            if ($this->readmePath !== null) {
                $raw = $this->gitService->getBlob($this->repo, $this->currentRef, $this->readmePath);
                $this->readmeContent = $raw;
                $rendered = $this->renderer->renderDocument($this->readmePath, $raw, 80);
                $this->readmeViewport->setContent($rendered);
            } else {
                $emptyMsg = Style::create()->foreground(TokyoNight::MUTED)->padding(2)->render("No README found for this repository. Press 'n' to create one.");
                $this->readmeViewport->setContent($emptyMsg);
            }
        } catch (Throwable $e) {
            $this->readmeViewport->setContent("Error loading README: " . $e->getMessage());
        }
    }

    private function loadFiles(string $subpath = ''): void
    {
        $this->currentSubpath = $subpath;
        try {
            $entries = $this->gitService->getTree($this->repo, $this->currentRef, $subpath);

            // Match Go (FileItems.Less): directories first, then files, each
            // sorted alphabetically by name.
            usort($entries, static function ($a, $b): int {
                $ad = $a->isDirectory();
                $bd = $b->isDirectory();
                if ($ad !== $bd) {
                    return $ad ? -1 : 1;
                }
                return strcmp($a->name, $b->name);
            });

            $items = [];
            foreach ($entries as $e) {
                $items[] = [
                    'id' => $e->path,
                    'label' => $e->name,
                    'name' => $e->name,
                    'type' => $e->type,
                    'path' => $e->path,
                    'mode' => $e->mode,
                    'size' => $e->size,
                    'isDir' => $e->isDirectory(),
                ];
            }
            $this->filesSelector->setItems($items);
        } catch (Throwable $e) {
            $this->filesSelector->setItems([['id' => 'err', 'label' => 'Error: ' . $e->getMessage()]]);
        }
    }

    private function loadCommits(): void
    {
        try {
            $commits = $this->gitService->getCommits($this->repo, $this->currentRef, 50);
            $items = [];
            foreach ($commits as $c) {
                $date = date('Y-m-d', $c->timestamp);
                $items[] = [
                    'id' => $c->hash,
                    'label' => Style::create()->foreground(TokyoNight::MUTED)->render($c->shortHash) . ' ' . $c->subject . Style::create()->foreground(TokyoNight::MUTED)->render(" ({$c->authorName}, {$date})"),
                    'commit' => $c,
                    'command' => $c->hash,
                ];
            }
            $this->logSelector->setItems($items);
        } catch (Throwable) {
            $this->logSelector->setItems([]);
        }
    }

    private function loadBranchesAndTags(): void
    {
        try {
            $refs = $this->gitService->getRefs($this->repo);
            $bItems = [];
            foreach ($refs->branches as $b) {
                $isCurrent = ($b === $refs->currentBranch);
                $bItems[] = [
                    'id' => $b,
                    'label' => ($isCurrent ? '• ' : '  ') . $b,
                    'current' => $isCurrent,
                    'command' => $b,
                ];
            }
            $this->branchesSelector->setItems($bItems);

            $tItems = [];
            foreach ($refs->tags as $t) {
                $tItems[] = ['id' => $t, 'label' => '🏷️ ' . $t, 'command' => $t];
            }
            $this->tagsSelector->setItems($tItems);
        } catch (Throwable) {
            $this->branchesSelector->setItems([]);
            $this->tagsSelector->setItems([]);
        }
    }

    private function loadPRs(): void
    {
        if ($this->backend === null) {
            $this->prsSelector->setItems([
                ['id' => 'none', 'label' => 'No active pull requests in local repository'],
            ]);
            return;
        }

        try {
            $prs = $this->backend->listPullRequests($this->repo->name);
            if (empty($prs)) {
                $this->prsSelector->setItems([
                    ['id' => 'empty', 'label' => 'No pull requests found. Press "n" to open one.'],
                ]);
                return;
            }

            $items = [];
            foreach ($prs as $pr) {
                $stateIcon = match ($pr->status) {
                    'open' => '🟢 #',
                    'merged' => '🟣 #',
                    default => '🔴 #',
                };
                $items[] = [
                    'id' => (string) $pr->number,
                    'label' => $stateIcon . $pr->number . ' ' . $pr->title . ' (' . $pr->authorUsername . ')',
                    'pr' => $pr,
                    'command' => $pr->title,
                ];
            }
            $this->prsSelector->setItems($items);
        } catch (Throwable) {
            $this->prsSelector->setItems([
                ['id' => 'none', 'label' => 'No pull requests found.'],
            ]);
        }
    }

    private function loadProfile(): void
    {
        $sb = [];
        $sb[] = Style::create()->bold(true)->foreground(TokyoNight::ACTIVE_BORDER)->render("📦 " . $this->repo->name);
        $sb[] = $this->repo->description !== '' ? $this->repo->description : 'No description provided.';
        $sb[] = '';
        $sb[] = "Default branch : " . Style::create()->foreground(TokyoNight::CYAN)->render($this->repo->defaultBranch);
        $sb[] = "Path           : " . $this->repo->path;
        $sb[] = "Visibility     : " . ($this->repo->isPrivate ? 'Private' : 'Public');
        $sb[] = "Type           : " . ($this->repo->isBare ? 'Bare repository' : 'Standard repository');
        $this->profileViewport->setContent(implode("\n", $sb));
    }

    public function update(InputEvent $event): ?string
    {
        // 1. Modals & confirmation dialogs
        if ($this->activeModal !== null) {
            $action = $this->activeModal->update($event);
            if ($action === 'cancel' || $action === 'submit') {
                $this->activeModal = null;
            }
            return null;
        }

        if ($this->commitModal !== null) {
            $action = $this->commitModal->update($event);
            if ($action === 'cancel') {
                $this->commitModal = null;
                return null;
            }
            if ($action === 'submit') {
                $msg = trim((string) $this->commitModal->getFieldValue('message'));
                $this->commitModal = null;
                if ($this->activeEditor !== null) {
                    $newContent = $this->activeEditor->getValue();
                    $this->saveFileCommit($this->editingTargetFile ?? 'README.md', $newContent, $msg);
                    $this->activeEditor = null;
                }
                return null;
            }
            return null;
        }

        if ($this->prFormModal !== null) {
            $action = $this->prFormModal->update($event);
            if ($action === 'cancel') {
                $this->prFormModal = null;
                return null;
            }
            if ($action === 'submit') {
                $head = trim((string) $this->prFormModal->getFieldValue('head'));
                $base = trim((string) $this->prFormModal->getFieldValue('base'));
                $title = trim((string) $this->prFormModal->getFieldValue('title'));
                $desc = trim((string) $this->prFormModal->getFieldValue('description'));
                $this->prFormModal = null;
                if ($this->backend !== null && $title !== '') {
                    try {
                        $this->backend->createPullRequest(
                            targetRepoName: $this->repo->name,
                            authorUsername: $this->currentUser?->username ?? 'admin',
                            title: $title,
                            description: $desc,
                            headBranch: $head,
                            baseBranch: $base,
                        );
                        $this->loadPRs();
                    } catch (Throwable) {
                    }
                }
                return null;
            }
            return null;
        }

        if ($this->prCommentModal !== null) {
            $action = $this->prCommentModal->update($event);
            if ($action === 'cancel') {
                $this->prCommentModal = null;
                return null;
            }
            if ($action === 'submit') {
                $body = trim((string) $this->prCommentModal->getFieldValue('body'));
                $this->prCommentModal = null;
                if ($this->backend !== null && $this->selectedPR !== null && $body !== '') {
                    try {
                        $this->backend->addPullRequestComment(
                            targetRepoName: $this->repo->name,
                            number: $this->selectedPR->number,
                            authorUsername: $this->currentUser?->username ?? 'admin',
                            body: $body,
                        );
                        $this->openPRDetail($this->selectedPR);
                    } catch (Throwable) {
                    }
                }
                return null;
            }
            return null;
        }

        if ($this->prConfirmDeleteModal !== null) {
            $action = $this->prConfirmDeleteModal->update($event);
            if ($action === 'cancel') {
                $this->prConfirmDeleteModal = null;
                return null;
            }
            if ($action === 'submit') {
                $this->prConfirmDeleteModal = null;
                if ($this->backend !== null && $this->selectedPR !== null) {
                    try {
                        $this->backend->closePullRequest(
                            targetRepoName: $this->repo->name,
                            number: $this->selectedPR->number,
                            username: $this->currentUser?->username ?? 'admin',
                        );
                        $this->viewingPRDetail = false;
                        $this->selectedPR = null;
                        $this->loadPRs();
                    } catch (Throwable) {
                    }
                }
                return null;
            }
            return null;
        }

        // 2. If editor is active
        if ($this->activeEditor !== null) {
            if ($event instanceof KeyMsg) {
                if ($event->is('esc')) {
                    $this->activeEditor = null;
                    return null;
                }
                if ($event->is('ctrl+s')) {
                    // Open commit message modal
                    $this->openCommitModal();
                    return null;
                }
            }
            $this->activeEditor->update($event);
            return null;
        }

        // 3. Global shortcuts
        if ($event instanceof KeyMsg) {
            if ($event->is('q')) {
                return 'quit';
            }
            if ($event->is('?')) {
                $this->showHelp = !$this->showHelp;
                return null;
            }
            if ($event->is(KeyMsg::ESC)) {
                if ($this->viewingRefsPreview) {
                    $this->viewingRefsPreview = false;
                    return null;
                }
                if ($this->viewingBlame) {
                    $this->viewingBlame = false;
                    return null;
                }
                if ($this->viewingFile) {
                    $this->viewingFile = false;
                    return null;
                }
                if ($this->viewingCommitDiff) {
                    $this->viewingCommitDiff = false;
                    return null;
                }
                if ($this->viewingPRDetail) {
                    $this->viewingPRDetail = false;
                    $this->selectedPR = null;
                    return null;
                }
                if ($this->onBackCallback !== null) {
                    ($this->onBackCallback)();
                    return null;
                }
            }
        }

        if ($event instanceof MouseMsg && $event->isLeftClick()) {
            if ($this->statusbar->isHelpClicked($event)) {
                $this->showHelp = !$this->showHelp;
                return null;
            }
        }

        // 4. Update Tabs first
        $oldTab = $this->tabs->getActiveTab();
        $this->tabs->update($event);
        if ($this->tabs->getActiveTab() !== $oldTab) {
            $this->viewingFile = false;
            $this->viewingCommitDiff = false;
            $this->viewingBlame = false;
            $this->viewingRefsPreview = false;
            $this->viewingPRDetail = false;
            $this->updateStatusbar();
            return null;
        }

        // 5. Dispatch to current tab component
        switch ($this->tabs->getActiveTab()) {
            case self::TAB_README:
                if ($event instanceof KeyMsg) {
                    if ($event->is('e')) {
                        $this->openReadmeEditor();
                        return null;
                    }
                    if ($event->is('n') && $this->readmePath === null) {
                        $this->openReadmeEditor(isNew: true);
                        return null;
                    }
                    if ($event->is('c')) {
                        echo "\x1b]52;c;" . base64_encode($this->readmeContent) . "\x07";
                        return null;
                    }
                }
                $this->readmeViewport->update($event);
                break;

            case self::TAB_FILES:
                if ($this->viewingRefsPreview) {
                    if ($event instanceof KeyMsg && ($event->is('esc') || $event->is('q'))) {
                        $this->viewingRefsPreview = false;
                        return null;
                    }
                    $this->refsPreviewViewport->update($event);
                } elseif ($this->viewingBlame) {
                    if ($event instanceof KeyMsg && ($event->is('esc') || $event->is('b') || $event->is('q'))) {
                        $this->viewingBlame = false;
                        return null;
                    }
                    $this->blameViewport->update($event);
                } elseif ($this->viewingFile) {
                    if ($event instanceof KeyMsg) {
                        if ($event->is('b')) {
                            $this->openBlameView();
                            return null;
                        }
                        if ($event->is('p')) {
                            // Toggle preview between rendered and raw code
                            $this->isFileRendered = !$this->isFileRendered;
                            return null;
                        }
                        if ($event->is('e')) {
                            $this->openFileEditor($this->currentFilePath);
                            return null;
                        }
                        if ($event->is('d')) {
                            $this->openDeleteFileModal($this->currentFilePath);
                            return null;
                        }
                    }

                    if ($this->isFileRendered) {
                        $this->renderedDocViewport->update($event);
                    } else {
                        $codeRes = $this->fileViewer->update($event);
                        if ($codeRes === 'goto_def' || $codeRes === 'find_refs') {
                            $this->handleCodeIntel($codeRes);
                        }
                    }
                } else {
                    if ($event instanceof KeyMsg) {
                        if ($event->is('n')) {
                            $this->openNewFileModal();
                            return null;
                        }
                        if ($event->is('e')) {
                            $sel = $this->filesSelector->getSelectedItem();
                            if (is_array($sel) && ($sel['type'] ?? '') !== 'tree' && ($sel['id'] ?? '') !== '..') {
                                $this->openFileEditor((string) $sel['path']);
                                return null;
                            }
                        }
                        if ($event->is('d')) {
                            $sel = $this->filesSelector->getSelectedItem();
                            if (is_array($sel) && ($sel['type'] ?? '') !== 'tree' && ($sel['id'] ?? '') !== '..') {
                                $this->openDeleteFileModal((string) $sel['path']);
                                return null;
                            }
                        }
                    }

                    $res = $this->filesSelector->update($event);
                    if ($res === 'select') {
                        $selected = $this->filesSelector->getSelectedItem();
                        if (is_array($selected)) {
                            if (($selected['type'] ?? '') === 'tree') {
                                $this->loadFiles($selected['path']);
                            } else {
                                $this->openFile($selected['path']);
                            }
                        }
                    } elseif ($res === 'back' && $this->currentSubpath !== '') {
                        // Navigate to the parent directory (Go: backtab/esc in a subtree).
                        $parent = dirname($this->currentSubpath);
                        $this->loadFiles($parent === '.' ? '' : $parent);
                        return null;
                    }
                }
                break;

            case self::TAB_LOG:
                if ($this->viewingCommitDiff) {
                    if ($event instanceof KeyMsg && $event->is('c') && $this->currentCommit !== null) {
                        try {
                            $diff = $this->gitService->getDiff($this->repo, $this->currentCommit->hash);
                            echo "\x1b]52;c;" . base64_encode($diff->rawDiff) . "\x07";
                        } catch (Throwable) {
                        }
                        return null;
                    }
                    $this->commitDiffViewport->update($event);
                } else {
                    $res = $this->logSelector->update($event);
                    if ($res === 'select') {
                        $selected = $this->logSelector->getSelectedItem();
                        if (is_array($selected) && isset($selected['commit']) && $selected['commit'] instanceof CommitModel) {
                            $this->openCommitDiff($selected['commit']);
                        }
                    }
                }
                break;

            case self::TAB_BRANCHES:
                $res = $this->branchesSelector->update($event);
                if ($res === 'select') {
                    $selected = $this->branchesSelector->getSelectedItem();
                    if (is_array($selected)) {
                        $this->currentRef = $selected['id'];
                        $this->header->setBadges([$this->currentRef, $this->repo->isPrivate ? 'private' : 'public']);
                        $this->loadReadme();
                        $this->loadFiles('');
                        $this->loadCommits();
                    }
                }
                break;

            case self::TAB_TAGS:
                $this->tagsSelector->update($event);
                break;

            case self::TAB_PRS:
                if ($this->viewingPRDetail) {
                    $this->handlePRDetailKeys($event);
                } else {
                    if ($event instanceof KeyMsg) {
                        if ($event->is('n')) {
                            $this->openPRCreateModal();
                            return null;
                        }
                    }
                    $res = $this->prsSelector->update($event);
                    if ($res === 'select') {
                        $sel = $this->prsSelector->getSelectedItem();
                        if (is_array($sel) && isset($sel['pr']) && $sel['pr'] instanceof PullRequestModel) {
                            $this->openPRDetail($sel['pr']);
                        }
                    }
                }
                break;

            case self::TAB_PROFILE:
                $this->profileViewport->update($event);
                break;

            case self::TAB_SETTINGS:
                $this->settingsForm->update($event);
                break;
        }

        $this->updateStatusbar();
        return null;
    }

    private function handleCodeIntel(string $action): void
    {
        if ($this->codeIntel === null) {
            return;
        }

        try {
            $line = $this->fileViewer->getCursorLine();
            $sha = $this->currentRef;
            if ($action === 'goto_def') {
                $def = $this->codeIntel->findDefinition($this->repo->name, $sha, $this->currentFilePath, $line, 0);
                if ($def !== null) {
                    $this->openFile($def->path);
                    $this->fileViewer->setCursorLine($def->line);
                }
            } elseif ($action === 'find_refs') {
                $refs = $this->codeIntel->findReferences($this->repo->name, $sha, $this->currentFilePath, $line, 0);
                $lines = [];
                $lines[] = Style::create()->bold(true)->foreground(TokyoNight::CYAN)->render("References (" . count($refs) . "):");
                $lines[] = '';
                foreach ($refs as $r) {
                    $lines[] = sprintf("  📄 %s:%d:%d", $r->path, $r->line + 1, $r->col + 1);
                }
                $this->refsPreviewViewport->setContent(implode("\n", $lines));
                $this->viewingRefsPreview = true;
            }
        } catch (Throwable) {
        }
    }

    private function handlePRDetailKeys(InputEvent $event): void
    {
        if (!$event instanceof KeyMsg || $this->selectedPR === null) {
            return;
        }

        if ($this->prCommentMenuActive) {
            $this->prCommentMenuActive = false;
            if ($event->is('c') || $event->is('n')) {
                $this->openPRCommentModal();
                return;
            }
            if ($event->is('x') || $event->is('d')) {
                $this->openPRConfirmDeleteModal();
                return;
            }
            return;
        }

        if ($event->is('tab') || $event->is(KeyMsg::TAB)) {
            $this->prDetailTab = ($this->prDetailTab + 1) % 4;
            $this->renderPRDetailContent();
            return;
        }

        if ($event->is(KeyMsg::BACKTAB)) {
            $this->prDetailTab = ($this->prDetailTab - 1 + 4) % 4;
            $this->renderPRDetailContent();
            return;
        }

        if ($event->is('m') && $this->backend !== null && $this->selectedPR->status === 'open') {
            try {
                $this->backend->mergePullRequest(
                    targetRepoName: $this->repo->name,
                    number: $this->selectedPR->number,
                    mergedByUsername: $this->currentUser?->username ?? 'admin',
                );
                $this->selectedPR = $this->backend->getPullRequest($this->repo->name, $this->selectedPR->number);
                $this->renderPRDetailContent();
            } catch (Throwable) {
            }
            return;
        }

        if ($event->is('s') && $this->backend !== null) {
            try {
                if ($this->selectedPR->status === 'open') {
                    $this->selectedPR = $this->backend->closePullRequest($this->repo->name, $this->selectedPR->number, $this->currentUser?->username ?? 'admin');
                } else {
                    $this->selectedPR = $this->backend->reopenPullRequest($this->repo->name, $this->selectedPR->number, $this->currentUser?->username ?? 'admin');
                }
                $this->renderPRDetailContent();
            } catch (Throwable) {
            }
            return;
        }

        if ($event->is('c')) {
            $this->prCommentMenuActive = true;
            return;
        }

        if ($event->is('d')) {
            $this->openPRConfirmDeleteModal();
            return;
        }

        $this->prDetailViewport->update($event);
    }

    private function openPRDetail(PullRequestModel $pr): void
    {
        $this->selectedPR = $pr;
        $this->viewingPRDetail = true;
        $this->prDetailTab = 0;
        $this->renderPRDetailContent();
    }

    private function renderPRDetailContent(): void
    {
        if ($this->selectedPR === null) {
            return;
        }

        $tabsHeader = "[0: About]  [1: Commits]  [2: Changes]  [3: Comments]";
        $activeTabName = match ($this->prDetailTab) {
            0 => 'About',
            1 => 'Commits',
            2 => 'Changes',
            default => 'Comments',
        };

        $sb = [];
        $sb[] = Style::create()->bold(true)->foreground(TokyoNight::ACTIVE_BORDER)->render("#{$this->selectedPR->number} {$this->selectedPR->title}");
        $sb[] = "Status: " . $this->selectedPR->status . " | Author: " . $this->selectedPR->authorUsername;
        $sb[] = "Branch: " . $this->selectedPR->headBranch . " → " . $this->selectedPR->baseBranch;
        $sb[] = Style::create()->foreground(TokyoNight::MUTED)->render(str_repeat('─', 60));
        $sb[] = Style::create()->foreground(TokyoNight::CYAN)->render("Active Tab: {$activeTabName} (Press Tab to switch)");
        $sb[] = '';

        if ($this->prDetailTab === 0) {
            $sb[] = $this->selectedPR->description !== '' ? $this->selectedPR->description : 'No description provided.';
        } elseif ($this->prDetailTab === 1) {
            try {
                $commits = $this->gitService->getCommits($this->repo, $this->selectedPR->headBranch, 10);
                foreach ($commits as $c) {
                    $sb[] = "• " . $c->shortHash . " " . $c->subject . " (" . $c->authorName . ")";
                }
            } catch (Throwable) {
                $sb[] = "No commits found on branch {$this->selectedPR->headBranch}.";
            }
        } elseif ($this->prDetailTab === 2) {
            try {
                $diff = $this->gitService->getDiff($this->repo, $this->selectedPR->headBranch);
                $sb[] = $diff->rawDiff;
            } catch (Throwable) {
                $sb[] = "No diff available.";
            }
        } else {
            if ($this->backend !== null) {
                $comments = $this->backend->listPullRequestComments($this->repo->name, $this->selectedPR->number);
                if (empty($comments)) {
                    $sb[] = "No comments yet. Press 'c' then 'n' to add one.";
                } else {
                    foreach ($comments as $cm) {
                        $sb[] = "💬 @" . $cm->authorUsername . " (" . $cm->createdAt->format('Y-m-d H:i') . "):";
                        $sb[] = "   " . $cm->body;
                        $sb[] = '';
                    }
                }
            }
        }

        $this->prDetailViewport->setContent(implode("\n", $sb));
    }

    private function openPRCreateModal(): void
    {
        $headField = new TextInputField('head', 'Head branch (source)', $this->currentRef);
        $baseField = new TextInputField('base', 'Base branch (target)', $this->repo->defaultBranch);
        $titleField = new TextInputField('title', 'Title', '');
        $descField = new TextAreaField('description', 'Description', '');
        $this->prFormModal = new FormComponent('New Pull Request', 'Submit changes to repository', [
            $headField,
            $baseField,
            $titleField,
            $descField,
        ]);
    }

    private function openPRCommentModal(): void
    {
        $bodyField = new TextAreaField('body', 'Comment message', '');
        $this->prCommentModal = new FormComponent('New Comment', 'Comment on PR #' . $this->selectedPR?->number, [
            $bodyField,
        ]);
    }

    private function openPRConfirmDeleteModal(): void
    {
        $this->prConfirmDeleteModal = new FormComponent('Close Pull Request', 'Are you sure you want to close PR #' . $this->selectedPR?->number . '?', [
            new ToggleField('confirm', 'Confirm closing', true),
        ]);
    }

    private function openReadmeEditor(bool $isNew = false): void
    {
        $this->isEditingReadme = true;
        $this->editingTargetFile = $this->readmePath ?? 'README.md';
        $content = $isNew ? "# " . $this->repo->name . "\n\n" : $this->readmeContent;
        $this->activeEditor = new EditorComponent($this->editingTargetFile, $content);
    }

    private function openFileEditor(string $path): void
    {
        $this->isEditingReadme = false;
        $this->editingTargetFile = $path;
        try {
            $raw = $this->gitService->getBlob($this->repo, $this->currentRef, $path);
        } catch (Throwable) {
            $raw = '';
        }
        $this->activeEditor = new EditorComponent($path, $raw);
    }

    private function openNewFileModal(): void
    {
        $pathField = new TextInputField('path', 'File path', ($this->currentSubpath !== '' ? $this->currentSubpath . '/' : '') . 'new_file.txt');
        $this->activeModal = new FormComponent('Create New File', 'Add a file to repository', [
            $pathField,
        ]);
    }

    private function openDeleteFileModal(string $path): void
    {
        $this->activeModal = new FormComponent('Delete File', 'Delete ' . basename($path) . ' from repository?', [
            new ToggleField('confirm', 'Confirm delete', true),
        ]);
    }

    private function openCommitModal(): void
    {
        $defaultMsg = 'Update ' . basename($this->editingTargetFile ?? 'file');
        $msgField = new TextInputField('message', 'Commit message', $defaultMsg);
        $this->commitModal = new FormComponent('Commit Changes', 'Save file and commit to Git', [
            $msgField,
        ]);
    }

    private function saveFileCommit(string $path, string $content, string $message): void
    {
        $authorName = $this->currentUser?->name ?? 'codes.sh';
        $authorEmail = $this->currentUser?->email ?? 'codes@sh';
        try {
            $this->plumbing->writeFile(
                repo: $this->repo,
                branch: $this->currentRef,
                filePath: $path,
                content: $content,
                message: $message,
                authorName: $authorName,
                authorEmail: $authorEmail,
            );
            $this->loadReadme();
            $this->loadFiles($this->currentSubpath);
            $this->loadCommits();
        } catch (Throwable) {
        }
    }

    private function openBlameView(): void
    {
        $this->viewingBlame = true;
        $lines = $this->gitService->getBlame($this->repo, $this->currentRef, $this->currentFilePath);
        $this->blameViewport->setContent(implode("\n", $lines));
    }

    private function openFile(string $path): void
    {
        $this->currentFilePath = $path;
        $this->viewingFile = true;
        $this->viewingBlame = false;
        $this->viewingRefsPreview = false;
        try {
            $content = $this->gitService->getBlob($this->repo, $this->currentRef, $path);
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            if (in_array($ext, ['md', 'markdown', 'csv', 'tsv', 'html'], true)) {
                $this->isFileRendered = true;
                $rendered = $this->renderer->renderDocument($path, $content, 80);
                $this->renderedDocViewport->setContent($rendered);
            } else {
                $this->isFileRendered = false;
                $this->fileViewer->setCode($content, $path);
            }
        } catch (Throwable $e) {
            $this->isFileRendered = false;
            $this->fileViewer->setCode("Error reading file: " . $e->getMessage(), $path);
        }
    }

    private function openCommitDiff(CommitModel $commit): void
    {
        $this->currentCommit = $commit;
        $this->viewingCommitDiff = true;
        try {
            $diff = $this->gitService->getDiff($this->repo, $commit->hash);
            $sb = [];
            $sb[] = Style::create()->bold(true)->foreground(TokyoNight::ACTIVE_BORDER)->render("Commit: " . $commit->hash);
            $sb[] = "Author: " . $commit->authorName . " <" . $commit->authorEmail . ">";
            $sb[] = "Date:   " . date('Y-m-d H:i:s', $commit->timestamp);
            $sb[] = '';
            $sb[] = $commit->subject;
            if ($commit->body !== '') {
                $sb[] = '';
                $sb[] = $commit->body;
            }
            $sb[] = '';
            $sb[] = Style::create()->foreground(TokyoNight::MUTED)->render(str_repeat('─', 60));
            $sb[] = "Diff (+{$diff->totalAdditions} / -{$diff->totalDeletions}):";
            $sb[] = '';
            $sb[] = $diff->rawDiff;

            $this->commitDiffViewport->setContent(implode("\n", $sb));
        } catch (Throwable $e) {
            $this->commitDiffViewport->setContent("Error loading diff: " . $e->getMessage());
        }
    }

    private function updateStatusbar(): void
    {
        $percent = match ($this->tabs->getActiveTab()) {
            self::TAB_README => $this->readmeViewport->getScrollPercentage(),
            self::TAB_FILES => $this->viewingFile ? ($this->isFileRendered ? $this->renderedDocViewport->getScrollPercentage() : 0) : 0,
            self::TAB_LOG => $this->viewingCommitDiff ? $this->commitDiffViewport->getScrollPercentage() : 0,
            default => 0,
        };

        $this->statusbar->setStatus(
            $this->repo->name,
            $this->currentRef,
            $this->tabs->getActiveTabName(),
            "≡ {$percent}%"
        );
    }

    public function view(int $width, int $height): string
    {
        if ($width <= 0 || $height <= 0) {
            return '';
        }

        // Top Header & Tabs (2 lines)
        $headerView = $this->header->view($width, 1);
        $tabsView = $this->tabs->view($width, 1);

        // Bottom Statusbar (1 line)
        $statusbarView = $this->statusbar->view($width, 1);

        // Optional Help overlay in footer
        $helpHeight = $this->showHelp ? 2 : 0;
        $helpView = '';
        if ($this->showHelp) {
            $helpStyle = Style::create()->background(TokyoNight::STATUS_BAR)->foreground(TokyoNight::FG)->width($width);
            $helpView = $helpStyle->render("  [tab/shift+tab] Change tab • [j/k/↑/↓] Navigate • [e] Edit • [n] New • [d] Delete • [c] Copy • [b] Blame • [esc] Back • [q] Quit");
        }

        // Available body height
        $bodyHeight = max(1, $height - 2 - 1 - $helpHeight);

        // If active editor or modal
        if ($this->activeEditor !== null) {
            $bodyView = $this->activeEditor->view($width, $bodyHeight);
        } elseif ($this->commitModal !== null) {
            $bodyView = $this->commitModal->view($width, $bodyHeight);
        } elseif ($this->prFormModal !== null) {
            $bodyView = $this->prFormModal->view($width, $bodyHeight);
        } elseif ($this->prCommentModal !== null) {
            $bodyView = $this->prCommentModal->view($width, $bodyHeight);
        } elseif ($this->prConfirmDeleteModal !== null) {
            $bodyView = $this->prConfirmDeleteModal->view($width, $bodyHeight);
        } elseif ($this->activeModal !== null) {
            $bodyView = $this->activeModal->view($width, $bodyHeight);
        } else {
            $bodyView = $this->renderCurrentTab($width, $bodyHeight);
        }

        $components = [$headerView, $tabsView, $bodyView];
        if ($this->showHelp) {
            $components[] = $helpView;
        }
        $components[] = $statusbarView;

        return implode("\n", $components);
    }

    private function renderCurrentTab(int $width, int $height): string
    {
        return match ($this->tabs->getActiveTab()) {
            self::TAB_README => $this->readmeViewport->view($width, $height),
            self::TAB_FILES => $this->renderFilesTab($width, $height),
            self::TAB_LOG => $this->viewingCommitDiff
                ? $this->commitDiffViewport->view($width, $height)
                : $this->logSelector->view($width, $height),
            self::TAB_BRANCHES => $this->branchesSelector->view($width, $height),
            self::TAB_TAGS => $this->tagsSelector->view($width, $height),
            self::TAB_PRS => $this->viewingPRDetail
                ? $this->prDetailViewport->view($width, $height)
                : $this->prsSelector->view($width, $height),
            self::TAB_PROFILE => $this->profileViewport->view($width, $height),
            self::TAB_SETTINGS => $this->settingsForm->view($width, $height),
            default => '',
        };
    }

    private function renderFilesTab(int $width, int $height): string
    {
        if ($this->viewingRefsPreview) {
            return $this->refsPreviewViewport->view($width, $height);
        }
        if ($this->viewingBlame) {
            return $this->blameViewport->view($width, $height);
        }
        if ($this->viewingFile) {
            return $this->isFileRendered
                ? $this->renderedDocViewport->view($width, $height)
                : $this->fileViewer->view($width, $height);
        }
        return $this->filesSelector->view($width, $height);
    }
}

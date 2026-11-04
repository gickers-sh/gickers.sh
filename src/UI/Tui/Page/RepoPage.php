<?php

declare(strict_types=1);

namespace App\UI\Tui\Page;

use App\CodeIntel\Application\Query\CodeIntelQuery;
use App\CodeIntel\Domain\SymbolLocation;
use App\Identity\Domain\Model\User;
use App\PullRequest\Application\MergePullRequest;
use App\PullRequest\Application\Query\PullRequestQuery;
use App\PullRequest\Domain\Model\PullRequest;
use App\PullRequest\Domain\PullRequestStatus;
use App\Repo\Application\UpdateRepoSettings;
use App\Repo\Domain\Git\Commit;
use App\Repo\Domain\Git\GitRepository;
use App\Repo\Domain\Git\TreeEntry;
use App\Repo\Domain\Model\Repo;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Domain\Exception\NotFound;
use App\UI\Tui\Input\Keys;
use App\UI\Tui\Render\Screen;
use App\UI\Tui\Render\TermstrapRenderer;

/**
 * Single-repository browser: tabs for readme, files, commits, branches, tags,
 * pull requests and settings. The file tab opens a syntax-highlighted code
 * viewer with keyboard go-to-definition / find-references backed by
 * {@see CodeIntelQuery}. Keyboard-only (symfony/tui 8.1 has no mouse support).
 *
 * Mirrors Go `pkg/ui/pages/repo`. Works both on a stored {@see Repo} (feed
 * navigation) and a bare/non-bare {@see GitRepository} opened by path (soft:browse),
 * in which case `$db` is null and settings/pull requests are read-only.
 */
final class RepoPage extends AbstractPage
{
    private const TAB_README = 0;
    private const TAB_FILES = 1;
    private const TAB_COMMITS = 2;
    private const TAB_BRANCHES = 3;
    private const TAB_TAGS = 4;
    private const TAB_PULLS = 5;
    private const TAB_SETTINGS = 6;

    /** @var list<string> */
    private const TABS = ['Readme', 'Files', 'Commits', 'Branches', 'Tags', 'Pull Requests', 'Settings'];

    private string $name;
    private string $ref;
    private int $tab = self::TAB_README;
    private int $sel = 0;
    private int $scroll = 0;
    private ?string $flash = null;

    // Files tab
    private string $subpath = '';
    private bool $fileOpen = false;
    private string $filePath = '';
    /** @var list<string> */
    private array $fileRaw = [];
    private int $cursorLine = 0;
    private int $cursorCol = 0;

    // References overlay (inside the file viewer)
    private bool $refsOpen = false;
    /** @var list<SymbolLocation> */
    private array $refs = [];
    private int $refSel = 0;

    // Commits tab
    private bool $diffOpen = false;
    /** @var list<string> */
    private array $diffLines = [];

    // Settings tab
    private bool $settingsEditing = false;
    /** @var array{description: string, private: bool} */
    private array $settingsForm = ['description' => '', 'private' => false];
    private int $settingsField = 0;

    /** @var array<string,string> ref => sha */
    private array $shaCache = [];
    /** @var array{key: string, lines: list<string>}|null */
    private ?array $readmeCache = null;
    /** @var array<string, list<TreeEntry>> */
    private array $treeCache = [];
    /** @var list<Commit>|null */
    private ?array $commits = null;
    /** @var array{branches: list<string>, tags: list<string>, current: string}|null */
    private ?array $refsData = null;
    /** @var list<PullRequest>|null */
    private ?array $pullsCache = null;
    private bool $prDetailOpen = false;
    private bool $prMergeConfirm = false;
    private bool $prDiffOpen = false;
    /** @var list<string> */
    private array $prDiffLines = [];
    private int $prDiffScroll = 0;

    public function __construct(
        private readonly GitRepository $git,
        private readonly ?Repo $db,
        private readonly ?User $actor,
        private readonly GitRepositoryReader $reader,
        private readonly TermstrapRenderer $renderer,
        private readonly CodeIntelQuery $codeIntel,
        private readonly UpdateRepoSettings $updateSettings,
        private readonly ?PullRequestQuery $pullRequestQuery = null,
        private readonly ?MergePullRequest $mergePullRequest = null,
    ) {
        $this->name = $this->db?->nameString() ?? $this->git->name;
        $this->ref = $this->git->defaultBranch;
        if (null !== $this->db) {
            $this->settingsForm = ['description' => $this->db->description(), 'private' => $this->db->isPrivate()];
        }
    }

    public function handleKey(string $seq): bool
    {
        $this->flash = null;

        if ($this->settingsEditing) {
            return $this->handleSettingsKey($seq);
        }
        if ($this->refsOpen) {
            return $this->handleRefsKey($seq);
        }
        if ($this->fileOpen) {
            return $this->handleFileKey($seq);
        }
        if ($this->diffOpen) {
            return $this->handleDiffKey($seq);
        }
        if ($this->prDiffOpen) {
            return $this->handlePrDiffKey($seq);
        }
        if ($this->prMergeConfirm) {
            return $this->handlePrMergeConfirmKey($seq);
        }
        if ($this->prDetailOpen) {
            return $this->handlePrDetailKey($seq);
        }

        return $this->handleTabKey($seq);
    }

    private function handleTabKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if (Keys::CTRL_C === $tok) {
            $this->host->quit();

            return true;
        }
        if (Keys::ESCAPE === $tok || 'q' === $txt) {
            $this->host->navigateToSelection();

            return true;
        }
        if (Keys::TAB === $tok) {
            $this->switchTab(($this->tab + 1) % \count(self::TABS));

            return true;
        }
        if (Keys::BACKTAB === $tok) {
            $this->switchTab(($this->tab + \count(self::TABS) - 1) % \count(self::TABS));

            return true;
        }
        if (null !== $txt && ctype_digit($txt) && '1' <= $txt && $txt <= '7') {
            $this->switchTab((int) $txt - 1);

            return true;
        }

        return match ($this->tab) {
            self::TAB_README => $this->handleDocScroll($tok, $txt, $this->readmeLineCount()),
            self::TAB_FILES => $this->handleFilesTabKey($tok, $txt),
            self::TAB_COMMITS => $this->handleCommitsTabKey($tok, $txt),
            self::TAB_BRANCHES => $this->handleRefListKey($tok, $txt, $this->branchList(), true),
            self::TAB_TAGS => $this->handleRefListKey($tok, $txt, $this->tagList(), false),
            self::TAB_SETTINGS => $this->handleSettingsTabKey($tok, $txt),
            self::TAB_PULLS => $this->handlePullsTabKey($tok, $txt),
            default => false,
        };
    }

    private function switchTab(int $tab): void
    {
        $this->tab = $tab;
        $this->sel = 0;
        $this->scroll = 0;
        $this->fileOpen = false;
        $this->diffOpen = false;
        $this->refsOpen = false;
        $this->settingsEditing = false;
    }

    private function handleDocScroll(string $tok, ?string $txt, int $total): bool
    {
        if (Keys::UP === $tok || 'k' === $txt) {
            $this->scroll = max(0, $this->scroll - 1);
        } elseif (Keys::DOWN === $tok || 'j' === $txt) {
            $this->scroll = max(0, min($this->scroll + 1, max(0, $total - 1)));
        } elseif (Keys::PAGE_UP === $tok) {
            $this->scroll = max(0, $this->scroll - 10);
        } elseif (Keys::PAGE_DOWN === $tok) {
            $this->scroll = max(0, min($this->scroll + 10, max(0, $total - 1)));
        } elseif (Keys::HOME === $tok || 'g' === $txt) {
            $this->scroll = 0;
        } elseif (Keys::END === $tok || 'G' === $txt) {
            $this->scroll = max(0, $total - 1);
        } else {
            return false;
        }

        return true;
    }

    private function handleListMove(string $tok, ?string $txt, int $count): bool
    {
        if (Keys::UP === $tok || 'k' === $txt) {
            $this->sel = max(0, $this->sel - 1);
        } elseif (Keys::DOWN === $tok || 'j' === $txt) {
            $this->sel = max(0, min($this->sel + 1, max(0, $count - 1)));
        } elseif (Keys::HOME === $tok || 'g' === $txt) {
            $this->sel = 0;
        } elseif (Keys::END === $tok || 'G' === $txt) {
            $this->sel = max(0, $count - 1);
        } else {
            return false;
        }

        return true;
    }

    private function handleFilesTabKey(string $tok, ?string $txt): bool
    {
        $entries = $this->tree();
        if (Keys::BACKSPACE === $tok && '' !== $this->subpath) {
            $this->subpath = $this->parentPath($this->subpath);
            $this->sel = 0;

            return true;
        }
        if (Keys::ENTER === $tok && [] !== $entries) {
            $entry = $entries[min($this->sel, \count($entries) - 1)];
            if ($entry->isDirectory()) {
                $this->subpath = $entry->path;
                $this->sel = 0;
            } else {
                $this->openFile($entry->path);
            }

            return true;
        }

        return $this->handleListMove($tok, $txt, \count($entries));
    }

    private function handleCommitsTabKey(string $tok, ?string $txt): bool
    {
        $commits = $this->commitList();
        if (Keys::ENTER === $tok && [] !== $commits) {
            $this->openDiff($commits[min($this->sel, \count($commits) - 1)]->hash);

            return true;
        }

        return $this->handleListMove($tok, $txt, \count($commits));
    }

    /**
     * @param list<string> $list
     */
    private function handleRefListKey(string $tok, ?string $txt, array $list, bool $branch): bool
    {
        if (Keys::ENTER === $tok && [] !== $list) {
            $this->switchRef($list[min($this->sel, \count($list) - 1)]);

            return true;
        }

        return $this->handleListMove($tok, $txt, \count($list));
    }

    private function handleSettingsTabKey(string $tok, ?string $txt): bool
    {
        if ('e' === $txt && $this->canEditSettings()) {
            $this->settingsEditing = true;
            $this->settingsField = 0;
            $this->settingsForm = [
                'description' => $this->db?->description() ?? '',
                'private' => $this->db?->isPrivate() ?? false,
            ];

            return true;
        }

        return false;
    }

    private function handleFileKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);
        $last = max(0, \count($this->fileRaw) - 1);

        if (Keys::ESCAPE === $tok || 'q' === $txt) {
            $this->fileOpen = false;

            return true;
        }
        if (Keys::UP === $tok || 'k' === $txt) {
            $this->cursorLine = max(0, $this->cursorLine - 1);
        } elseif (Keys::DOWN === $tok || 'j' === $txt) {
            $this->cursorLine = min($last, $this->cursorLine + 1);
        } elseif (Keys::LEFT === $tok) {
            $this->cursorCol = max(0, $this->cursorCol - 1);
        } elseif (Keys::RIGHT === $tok) {
            $this->cursorCol = min($this->currentLineLength(), $this->cursorCol + 1);
        } elseif (Keys::PAGE_UP === $tok) {
            $this->cursorLine = max(0, $this->cursorLine - 10);
        } elseif (Keys::PAGE_DOWN === $tok) {
            $this->cursorLine = min($last, $this->cursorLine + 10);
        } elseif (Keys::HOME === $tok || 'g' === $txt) {
            $this->cursorLine = 0;
        } elseif (Keys::END === $tok || 'G' === $txt) {
            $this->cursorLine = $last;
        } elseif ('d' === $txt) {
            $this->gotoDefinition();
        } elseif ('r' === $txt) {
            $this->findReferences();
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

    private function handlePullsTabKey(string $tok, ?string $txt): bool
    {
        $pulls = $this->pullList();
        if (Keys::ENTER === $tok && [] !== $pulls) {
            $this->prDetailOpen = true;

            return true;
        }

        if ('m' === $txt && [] !== $pulls && null !== $this->mergePullRequest) {
            $pr = $this->selectedPull();
            if ($pr?->isOpen()) {
                $this->prMergeConfirm = true;

                return true;
            }
        }

        return $this->handleListMove($tok, $txt, \count($pulls));
    }

    private function handlePrDetailKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if (Keys::ESCAPE === $tok || 'q' === $txt) {
            $this->prDetailOpen = false;

            return true;
        }

        if ('m' === $txt && null !== ($pr = $this->selectedPull()) && $pr->isOpen() && null !== $this->mergePullRequest) {
            $this->prMergeConfirm = true;

            return true;
        }

        if ('d' === $txt && null !== ($pr = $this->selectedPull()) && null !== $this->pullRequestQuery && null !== $this->db) {
            try {
                $raw = $this->pullRequestQuery->diff($this->actor, $this->db->name(), $pr->number());
                $this->prDiffLines = preg_split('/\r\n|\r|\n/', rtrim($raw, "\n")) ?: [];
                $this->prDiffScroll = 0;
                $this->prDiffOpen = true;
            } catch (\Throwable $e) {
                $this->flash = 'Failed to load diff: '.$e->getMessage();
            }

            return true;
        }

        return false;
    }

    private function handlePrMergeConfirmKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if ('y' === $txt || 'Y' === $txt) {
            $this->confirmMerge();

            return true;
        }

        if ('n' === $txt || 'N' === $txt || Keys::ESCAPE === $tok) {
            $this->prMergeConfirm = false;

            return true;
        }

        return true;
    }

    private function handlePrDiffKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if (Keys::ESCAPE === $tok || 'q' === $txt) {
            $this->prDiffOpen = false;

            return true;
        }

        $total = \count($this->prDiffLines);
        if (Keys::UP === $tok || 'k' === $txt) {
            $this->prDiffScroll = max(0, $this->prDiffScroll - 1);
        } elseif (Keys::DOWN === $tok || 'j' === $txt) {
            $this->prDiffScroll = max(0, min($this->prDiffScroll + 1, max(0, $total - 1)));
        }

        return true;
    }

    private function confirmMerge(): void
    {
        $pr = $this->selectedPull();
        if (null === $pr || null === $this->mergePullRequest || null === $this->db) {
            $this->prMergeConfirm = false;

            return;
        }

        try {
            ($this->mergePullRequest)($this->actor, $this->db->name(), $pr->number());
            $this->flash = sprintf('Merged PR #%d', $pr->number());
            $this->pullsCache = null;
            $this->prMergeConfirm = false;
            $this->prDetailOpen = false;
        } catch (\Throwable $e) {
            $this->flash = 'Merge failed: '.$e->getMessage();
            $this->prMergeConfirm = false;
        }
    }

    /**
     * @return list<PullRequest>
     */
    private function pullList(): array
    {
        if (null === $this->db || null === $this->pullRequestQuery) {
            return [];
        }

        if (null === $this->pullsCache) {
            try {
                $this->pullsCache = $this->pullRequestQuery->list($this->actor, $this->db->name());
            } catch (\Throwable) {
                $this->pullsCache = [];
            }
        }

        return $this->pullsCache;
    }

    private function selectedPull(): ?PullRequest
    {
        $pulls = $this->pullList();

        return $pulls[min($this->sel, max(0, \count($pulls) - 1))] ?? null;
    }

    private function handleDiffKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);
        if (Keys::ESCAPE === $tok || 'q' === $txt) {
            $this->diffOpen = false;

            return true;
        }

        return $this->handleDocScroll($tok, $txt, \count($this->diffLines));
    }

    private function handleSettingsKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if (Keys::ESCAPE === $tok) {
            $this->settingsEditing = false;
        } elseif (Keys::ENTER === $tok) {
            $this->saveSettings();
        } elseif (Keys::TAB === $tok || Keys::DOWN === $tok) {
            $this->settingsField = ($this->settingsField + 1) % 2;
        } elseif (Keys::BACKTAB === $tok || Keys::UP === $tok) {
            $this->settingsField = ($this->settingsField + 1) % 2;
        } elseif (1 === $this->settingsField && (Keys::SPACE === $tok || Keys::LEFT === $tok || Keys::RIGHT === $tok)) {
            $this->settingsForm['private'] = !$this->settingsForm['private'];
        } elseif (0 === $this->settingsField && Keys::BACKSPACE === $tok) {
            $this->settingsForm['description'] = mb_substr($this->settingsForm['description'], 0, -1);
        } elseif (0 === $this->settingsField && null !== $txt) {
            $this->settingsForm['description'] .= $txt;
        }

        return true;
    }

    // --- actions -----------------------------------------------------------

    private function openFile(string $path): void
    {
        try {
            $content = $this->reader->getBlob($this->git, $this->ref, $path);
        } catch (\Throwable $e) {
            $this->flash = 'Cannot open '.$path;

            return;
        }
        $this->fileRaw = preg_split('/\r\n|\r|\n/', rtrim($content, "\n")) ?: [''];
        $this->filePath = $path;
        $this->fileOpen = true;
        $this->cursorLine = 0;
        $this->cursorCol = 0;
    }

    private function switchRef(string $ref): void
    {
        if ('' === $ref || $ref === $this->ref) {
            return;
        }
        $this->ref = $ref;
        $this->readmeCache = null;
        $this->treeCache = [];
        $this->commits = null;
        $this->subpath = '';
        $this->sel = 0;
        $this->scroll = 0;
        $this->flash = 'Switched to '.$ref;
    }

    private function openDiff(string $sha): void
    {
        try {
            $diff = $this->reader->getDiff($this->git, $sha);
        } catch (\Throwable) {
            $this->flash = 'Cannot load diff.';

            return;
        }
        $this->diffLines = preg_split('/\r\n|\r|\n/', rtrim($diff->rawDiff, "\n")) ?: [];
        $this->diffOpen = true;
        $this->scroll = 0;
    }

    private function gotoDefinition(): void
    {
        $sha = $this->sha();
        if ('' === $sha || !$this->codeIntel->has($this->name, $sha)) {
            $this->flash = 'No code intelligence index for this commit.';

            return;
        }
        $def = $this->codeIntel->findDefinition($this->name, $sha, $this->filePath, $this->cursorLine, $this->cursorCol);
        if (null === $def) {
            $this->flash = 'No definition found at cursor.';

            return;
        }
        $this->openLocation($def);
    }

    private function findReferences(): void
    {
        $sha = $this->sha();
        if ('' === $sha || !$this->codeIntel->has($this->name, $sha)) {
            $this->flash = 'No code intelligence index for this commit.';

            return;
        }
        $refs = $this->codeIntel->findReferences($this->name, $sha, $this->filePath, $this->cursorLine, $this->cursorCol);
        if ([] === $refs) {
            $this->flash = 'No references found at cursor.';

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
                $content = $this->reader->getBlob($this->git, $this->ref, $loc->path);
            } catch (\Throwable) {
                $this->flash = 'Cannot open '.$loc->path;

                return;
            }
            $this->fileRaw = preg_split('/\r\n|\r|\n/', rtrim($content, "\n")) ?: [''];
            $this->filePath = $loc->path;
            $this->fileOpen = true;
        }
        $this->cursorLine = max(0, min($loc->line, \count($this->fileRaw) - 1));
        $this->cursorCol = max(0, $loc->col);
        $this->flash = '→ '.$loc->path.':'.($loc->line + 1);
    }

    private function saveSettings(): void
    {
        if (null === $this->db) {
            $this->settingsEditing = false;

            return;
        }
        try {
            ($this->updateSettings)(
                $this->actor,
                $this->db->name(),
                $this->settingsForm['description'],
                null,
                $this->settingsForm['private'],
                null,
            );
        } catch (AccessDenied|NotFound|Conflict|InvalidInput $e) {
            $this->flash = $e->getMessage();

            return;
        }
        $this->settingsEditing = false;
        $this->flash = 'Settings saved.';
    }

    private function canEditSettings(): bool
    {
        return null !== $this->db && null !== $this->actor;
    }

    // --- data accessors (memoised) -----------------------------------------

    private function sha(): string
    {
        return $this->shaCache[$this->ref] ??= $this->resolveSha($this->ref);
    }

    private function resolveSha(string $ref): string
    {
        try {
            $commits = $this->reader->getCommits($this->git, $ref, 1, 0);
        } catch (\Throwable) {
            return '';
        }

        return $commits[0]->hash ?? '';
    }

    /**
     * @return list<TreeEntry>
     */
    private function tree(): array
    {
        $key = $this->ref.':'.$this->subpath;
        if (!isset($this->treeCache[$key])) {
            try {
                $this->treeCache[$key] = array_values($this->reader->getTree($this->git, $this->ref, $this->subpath));
            } catch (\Throwable) {
                $this->treeCache[$key] = [];
            }
        }

        return $this->treeCache[$key];
    }

    /**
     * @return list<Commit>
     */
    private function commitList(): array
    {
        if (null === $this->commits) {
            try {
                $this->commits = array_values($this->reader->getCommits($this->git, $this->ref, 100, 0));
            } catch (\Throwable) {
                $this->commits = [];
            }
        }

        return $this->commits;
    }

    /**
     * @return array{branches: list<string>, tags: list<string>, current: string}
     */
    private function refsData(): array
    {
        if (null === $this->refsData) {
            try {
                $refs = $this->reader->getRefs($this->git);
                $this->refsData = ['branches' => array_values($refs->branches), 'tags' => array_values($refs->tags), 'current' => $refs->currentBranch];
            } catch (\Throwable) {
                $this->refsData = ['branches' => [], 'tags' => [], 'current' => $this->ref];
            }
        }

        return $this->refsData;
    }

    /**
     * @return list<string>
     */
    private function branchList(): array
    {
        return $this->refsData()['branches'];
    }

    /**
     * @return list<string>
     */
    private function tagList(): array
    {
        return $this->refsData()['tags'];
    }

    private function currentLineLength(): int
    {
        $line = $this->fileRaw[$this->cursorLine] ?? '';

        return mb_strlen($line);
    }

    private function readmeLineCount(): int
    {
        return \count($this->readmeLines(120));
    }

    /**
     * @return list<string>
     */
    private function readmeLines(int $cols): array
    {
        $key = $this->ref.':'.$cols;
        if (null === $this->readmeCache || $this->readmeCache['key'] !== $key) {
            $doc = $this->reader->readme($this->git, $this->ref);
            if (null === $doc) {
                $lines = [];
            } elseif ($this->renderer->isAvailable()) {
                $ansi = $this->renderer->renderDocument($doc['path'], $doc['content'], max(20, $cols - 2));
                $lines = preg_split('/\r\n|\r|\n/', rtrim($ansi, "\n")) ?: [];
            } else {
                $lines = preg_split('/\r\n|\r|\n/', rtrim($doc['content'], "\n")) ?: [];
            }
            $this->readmeCache = ['key' => $key, 'lines' => $lines];
        }

        return $this->readmeCache['lines'];
    }

    private function parentPath(string $path): string
    {
        $pos = strrpos($path, '/');

        return false === $pos ? '' : substr($path, 0, $pos);
    }

    // --- rendering ---------------------------------------------------------

    protected function headerText(): string
    {
        $vis = $this->db?->isPrivate() ? ' (private)' : '';

        return ' '.$this->name.$vis.' @ '.$this->ref.' ';
    }

    protected function tabBarText(): string
    {
        return Screen::tabBar(self::TABS, $this->tab, 300);
    }

    protected function footerText(): string
    {
        if (null !== $this->flash) {
            return ' '.$this->flash;
        }
        if ($this->prDiffOpen) {
            return ' ↑/↓ scroll · Esc: back';
        }
        if ($this->prMergeConfirm) {
            return ' y: confirm merge · n/Esc: cancel';
        }
        if ($this->prDetailOpen) {
            return ' m: merge · d: diff · Esc: back';
        }
        if (self::TAB_PULLS === $this->tab) {
            return ' ↑/↓ move · Enter: view · m: merge · Tab: next · q: back';
        }
        if ($this->refsOpen) {
            return ' ↑/↓ move · Enter: jump · Esc: back';
        }
        if ($this->fileOpen) {
            return sprintf(' Ln %d, Col %d · d: definition · r: references · Esc: back', $this->cursorLine + 1, $this->cursorCol + 1);
        }
        if ($this->diffOpen) {
            return ' ↑/↓ scroll · Esc: back';
        }
        if ($this->settingsEditing) {
            return ' Enter: save · Tab: next · Esc: cancel';
        }

        return ' Tab: next · 1–7: jump · Enter: open · q: back';
    }

    protected function paintBody(int $cols, int $rows): array
    {
        if ($this->refsOpen) {
            return $this->paintRefs($cols, $rows);
        }
        if ($this->fileOpen) {
            return $this->paintFile($cols, $rows);
        }
        if ($this->diffOpen) {
            return $this->docWindow($this->colourDiff($this->diffLines), $cols, $rows);
        }

        return match ($this->tab) {
            self::TAB_README => $this->paintReadme($cols, $rows),
            self::TAB_FILES => $this->paintFiles($cols, $rows),
            self::TAB_COMMITS => $this->paintCommits($cols, $rows),
            self::TAB_BRANCHES => $this->paintRefList($this->branchList(), $this->refsData()['current'], 'No branches.', $cols, $rows),
            self::TAB_TAGS => $this->paintRefList($this->tagList(), '', 'No tags.', $cols, $rows),
            self::TAB_PULLS => $this->paintPulls($cols, $rows),
            self::TAB_SETTINGS => $this->paintSettings($cols, $rows),
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private function paintReadme(int $cols, int $rows): array
    {
        $lines = $this->readmeLines($cols);
        if ([] === $lines) {
            return $this->messageBody('This repository has no README.', $cols);
        }

        return $this->docWindow($lines, $cols, $rows);
    }

    /**
     * @return list<string>
     */
    private function paintFiles(int $cols, int $rows): array
    {
        $entries = $this->tree();
        $crumb = '  /'.$this->subpath;
        $header = "\e[2m".Screen::fit($crumb, $cols)."\e[0m";
        $bodyRows = max(1, $rows - 1);

        if ([] === $entries) {
            return array_merge([$header], $this->messageBody('Empty directory.', $cols));
        }

        $items = [];
        foreach ($entries as $entry) {
            $label = $entry->isDirectory() ? $entry->name.'/' : $entry->name;
            $meta = $entry->isFile() && null !== $entry->size ? $this->humanSize($entry->size) : '';
            $items[] = ['label' => $label, 'meta' => $meta];
        }

        return array_merge([$header], Screen::listView($items, $this->sel, $bodyRows, $cols));
    }

    /**
     * @return list<string>
     */
    private function paintCommits(int $cols, int $rows): array
    {
        $commits = $this->commitList();
        if ([] === $commits) {
            return $this->messageBody('No commits.', $cols);
        }

        $items = [];
        foreach ($commits as $commit) {
            $meta = $commit->shortHash.'  '.$this->relativeTime($commit->timestamp);
            $items[] = ['label' => $commit->subject, 'meta' => $meta];
        }

        return Screen::listView($items, $this->sel, $rows, $cols);
    }

    /**
     * @param list<string> $list
     *
     * @return list<string>
     */
    private function paintRefList(array $list, string $current, string $empty, int $cols, int $rows): array
    {
        if ([] === $list) {
            return $this->messageBody($empty, $cols);
        }

        $items = [];
        foreach ($list as $ref) {
            $marker = $ref === $this->ref ? 'current' : ($ref === $current ? 'default' : '');
            $items[] = ['label' => $ref, 'meta' => $marker];
        }

        return Screen::listView($items, $this->sel, $rows, $cols);
    }

    /**
     * @return list<string>
     */
    private function paintPulls(int $cols, int $rows): array
    {
        if (null === $this->db || null === $this->pullRequestQuery) {
            return $this->messageBody('Pull requests are unavailable for local repositories.', $cols);
        }

        if ($this->prDiffOpen) {
            $header = "\e[2m  Diff for PR #".($this->selectedPull()?->number() ?? 0)."\e[0m";

            return array_merge([$header], $this->docWindow($this->colourDiff($this->prDiffLines), $cols, max(1, $rows - 1)));
        }

        if ($this->prMergeConfirm) {
            $pr = $this->selectedPull();
            $lines = [
                '',
                sprintf('  Merge pull request #%d: %s?', $pr?->number() ?? 0, $pr?->title() ?? ''),
                sprintf('  into base branch "%s"', $pr?->baseRef() ?? ''),
                '',
                '  Press y to confirm merge, or n/Esc to cancel.',
            ];
            $box = Screen::box('Confirm merge', $lines, min(60, max(30, $cols - 4)));

            return Screen::centerBlock($box, $cols, $rows);
        }

        if ($this->prDetailOpen && null !== ($pr = $this->selectedPull())) {
            return $this->paintPullDetail($pr, $cols, $rows);
        }

        $pulls = $this->pullList();
        if ([] === $pulls) {
            return $this->messageBody('No pull requests.', $cols);
        }

        $items = [];
        foreach ($pulls as $pr) {
            $statusBadge = match ($pr->status()) {
                PullRequestStatus::Open => "\e[32mopen\e[0m",
                PullRequestStatus::Merged => "\e[35mmerged\e[0m",
                PullRequestStatus::Closed => "\e[31mclosed\e[0m",
            };
            $label = sprintf('#%d  %s', $pr->number(), $pr->title());
            $meta = sprintf('%s  %s → %s', $statusBadge, $pr->headRef(), $pr->baseRef());
            $items[] = ['label' => $label, 'meta' => $meta];
        }

        return Screen::listView($items, $this->sel, $rows, $cols);
    }

    /**
     * @return list<string>
     */
    private function paintPullDetail(PullRequest $pr, int $cols, int $rows): array
    {
        $statusStr = match ($pr->status()) {
            PullRequestStatus::Open => "\e[32mopen\e[0m",
            PullRequestStatus::Merged => "\e[35mmerged\e[0m",
            PullRequestStatus::Closed => "\e[31mclosed\e[0m",
        };
        $lines = [
            sprintf('  #%d: %s [%s]', $pr->number(), $pr->title(), $statusStr),
            sprintf('  Author: %s · Base: %s · Head: %s', $pr->author()->username(), $pr->baseRef(), $pr->headRef()),
            '',
            '  '.('' !== $pr->description() ? $pr->description() : 'No description.'),
            '',
        ];

        $comments = $this->pullRequestQuery?->comments($this->actor, $this->db?->name() ?? '', $pr->number()) ?? [];
        if ([] !== $comments) {
            $lines[] = '  Comments:';
            foreach ($comments as $c) {
                $lines[] = sprintf('    %s: %s', $c->author()->username(), $c->body());
            }
            $lines[] = '';
        }

        if ($pr->isOpen()) {
            $lines[] = '  m: merge · d: diff · Esc: back';
        } else {
            $lines[] = '  d: diff · Esc: back';
        }

        return $this->docWindow($lines, $cols, $rows);
    }

    /**
     * @return list<string>
     */
    private function paintSettings(int $cols, int $rows): array
    {
        if (null === $this->db) {
            return array_map(static fn (string $l): string => Screen::fit($l, $cols), [
                '',
                '  Local repository (not registered on this server).',
                '  Name         '.$this->name,
                '  Default ref  '.$this->git->defaultBranch,
            ]);
        }

        if ($this->settingsEditing) {
            $lines = [
                '',
                $this->editRow('Description', $this->settingsForm['description'], 0 === $this->settingsField),
                $this->toggleRow('Private', $this->settingsForm['private'], 1 === $this->settingsField),
            ];

            return array_map(static fn (string $l): string => Screen::fit($l, $cols), $lines);
        }

        $lines = [
            '',
            '  Name         '.$this->name,
            '  Project      '.($this->db->projectName() ?: '—'),
            '  Description  '.($this->db->description() ?: '—'),
            '  Private      '.($this->db->isPrivate() ? 'yes' : 'no'),
            '  Default ref  '.$this->ref,
            '',
            $this->canEditSettings() ? '  Press e to edit.' : '  Read-only (write access required to edit).',
        ];

        return array_map(static fn (string $l): string => Screen::fit($l, $cols), $lines);
    }

    /**
     * @return list<string>
     */
    private function paintRefs(int $cols, int $rows): array
    {
        $items = [];
        foreach ($this->refs as $loc) {
            $items[] = ['label' => $loc->path, 'meta' => ($loc->line + 1).':'.($loc->col + 1)];
        }
        $header = "\e[2m".Screen::fit('  '.\count($this->refs).' reference(s)', $cols)."\e[0m";

        return array_merge([$header], Screen::listView($items, $this->refSel, max(1, $rows - 1), $cols));
    }

    /**
     * @return list<string>
     */
    private function paintFile(int $cols, int $rows): array
    {
        $count = \count($this->fileRaw);
        $gutter = max(3, \strlen((string) $count));
        $codeWidth = max(1, $cols - $gutter - 2);
        $top = $count > $rows ? max(0, min($this->cursorLine - intdiv($rows, 2), $count - $rows)) : 0;

        $rowsOut = [];
        for ($r = 0; $r < $rows; ++$r) {
            $idx = $top + $r;
            if ($idx >= $count) {
                $rowsOut[] = Screen::blank($cols);
                continue;
            }
            $raw = $this->fileRaw[$idx];
            $code = $this->renderer->isAvailable()
                ? $this->renderer->renderSnippet($this->filePath, $raw, $codeWidth)
                : $raw;
            $num = str_pad((string) ($idx + 1), $gutter, ' ', \STR_PAD_LEFT);
            if ($idx === $this->cursorLine) {
                $g = "\e[7m".$num.'▶'."\e[0m".' ';
            } else {
                $g = "\e[2m".$num.'│'."\e[0m".' ';
            }
            $rowsOut[] = Screen::fit($g.Screen::truncate($code, $codeWidth), $cols);
        }

        return $rowsOut;
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function docWindow(array $lines, int $cols, int $rows): array
    {
        $total = \count($lines);
        $top = max(0, min($this->scroll, max(0, $total - 1)));
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
    private function colourDiff(array $lines): array
    {
        return array_map(static function (string $line): string {
            if (str_starts_with($line, '+') && !str_starts_with($line, '+++')) {
                return "\e[32m".$line."\e[0m";
            }
            if (str_starts_with($line, '-') && !str_starts_with($line, '---')) {
                return "\e[31m".$line."\e[0m";
            }
            if (str_starts_with($line, '@@')) {
                return "\e[36m".$line."\e[0m";
            }

            return $line;
        }, $lines);
    }

    private function editRow(string $label, string $value, bool $active): string
    {
        $text = sprintf('  %-12s %s%s', $label, $value, $active ? '▏' : '');

        return $active ? "\e[1m".$text."\e[0m" : $text;
    }

    private function toggleRow(string $label, bool $value, bool $active): string
    {
        $text = sprintf('  %-12s %s', $label, $value ? '[x]' : '[ ]');

        return $active ? "\e[1m".$text.'  (space)'."\e[0m" : $text;
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }

    private function relativeTime(int $timestamp): string
    {
        $diff = max(0, time() - $timestamp);
        if ($diff < 3600) {
            return max(1, intdiv($diff, 60)).'m ago';
        }
        if ($diff < 86400) {
            return intdiv($diff, 3600).'h ago';
        }

        return intdiv($diff, 86400).'d ago';
    }
}

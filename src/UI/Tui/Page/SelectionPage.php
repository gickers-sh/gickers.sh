<?php

declare(strict_types=1);

namespace App\UI\Tui\Page;

use App\Identity\Domain\Model\User;
use App\Repo\Application\CreateRepo;
use App\Repo\Application\Query\RepoQuery;
use App\Repo\Domain\Git\GitRepository;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoDirectory;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\UI\Tui\Input\Keys;
use App\UI\Tui\Page\Repo\AbstractRepoTab;
use App\UI\Tui\Render\Editor;
use App\UI\Tui\Render\Screen;
use App\UI\Tui\Render\Spinner;
use App\UI\Tui\Render\TermstrapRenderer;
use App\UI\Tui\Render\Theme;
use Symfony\Component\Process\Process;

/**
 * Landing page: a tabbed feed of readable repositories, the caller's own
 * repositories, server "about" and a profile tab, plus a new-repository modal
 * (via {@see CreateRepo}) and a help modal. Keyboard-only.
 *
 * Mirrors Go `pkg/ui/pages/selection`: the feed lists non-hidden repos the caller
 * can read ({@see RepoQuery::visibleTo()}); "My repositories" lists owned repos
 * ({@see RepoQuery::ownedBy()}).
 */
final class SelectionPage extends AbstractPage
{
    private const TAB_ABOUT = 0;
    private const TAB_FEED = 1;
    private const TAB_REPOS = 2;
    private const TAB_PROFILE = 3;

    /** @var list<string> */
    private const TABS = ['About', 'Feed', 'My Repositories', 'Profile'];

    private int $tab = self::TAB_ABOUT;

    /** @var array<int,int> per-tab selected index */
    private array $selected = [0, 0, 0, 0];
    private int $aboutScroll = 0;
    private int $aboutRows = 20;
    private int $lastAboutCols = 76;
    /** @var array{key: string, lines: list<string>}|null */
    private ?array $aboutCache = null;

    private bool $aboutDocResolved = false;
    /** @var array{path: string, content: string}|null */
    private ?array $aboutDoc = null;
    /** @var array{git: GitRepository, stage: 'tree'|'blob', process: Process, path?: string}|null */
    private ?array $aboutJob = null;
    /** @var array{key: string, process: Process, fallback: list<string>}|null */
    private ?array $aboutRenderJob = null;
    private Spinner $spinner;
    private bool $newRepoOpen = false;
    private bool $helpOpen = false;
    private bool $fuzzyOpen = false;
    private string $fuzzyQuery = '';
    private int $fuzzySel = 0;
    /** @var array{name: string, description: string, private: bool} */
    private array $form = ['name' => '', 'description' => '', 'private' => false];
    private int $field = 0;
    private ?string $error = null;
    private ?string $flash = null;

    /** @var array<int,int> painted-body-line → index into the currently displayed repo list */
    private array $listRowItem = [];
    /** @var list<Repo> the repo list currently painted (fuzzy-filtered or raw) */
    private array $listDisplayRepos = [];
    /** @var array{0:int,1:int,2:int}|null painted-body-lines of the new-repo modal fields [name, description, private] */
    private ?array $modalFieldRows = null;

    public function setFlash(string $flash): void
    {
        $this->flash = $flash;
    }

    /** @var list<Repo> */
    private array $feed = [];
    /** @var list<Repo> */
    private array $owned = [];

    public function __construct(
        private readonly RepoQuery $repoQuery,
        private readonly CreateRepo $createRepo,
        private readonly ServerSettings $settings,
        private readonly ?User $actor,
        private readonly ?GitRepositoryReader $reader = null,
        private readonly ?TermstrapRenderer $renderer = null,
        private readonly ?RepoDirectory $repoDirectory = null,
    ) {
        $this->spinner = new Spinner();
    }

    protected function load(): void
    {
        $this->feed = $this->repoQuery->visibleTo($this->actor);
        $this->owned = null !== $this->actor ? $this->repoQuery->ownedBy($this->actor) : [];
    }

    public function tick(?float $deltaTime = null): bool
    {
        $busy = false;

        if (null !== $this->aboutJob) {
            $busy = true;
            $this->pollAboutJob();
        }

        if (null !== $this->aboutRenderJob) {
            $busy = true;
            $this->pollAboutRenderJob();
        }

        if ($busy) {
            $this->spinner->advance($deltaTime);
        }

        return $busy;
    }

    private function pollAboutJob(): void
    {
        if (null === $this->aboutJob || $this->aboutJob['process']->isRunning()) {
            return;
        }

        $job = $this->aboutJob;
        $this->aboutJob = null;
        $proc = $job['process'];
        $git = $job['git'];

        if ('tree' === $job['stage']) {
            if (!$proc->isSuccessful() || null === $this->reader) {
                $this->aboutDocResolved = true;
                $this->aboutDoc = null;

                return;
            }
            $entries = $this->reader->parseTreeOutput($proc->getOutput());
            $entry = $this->reader->findReadmeEntry($entries);
            if (null === $entry) {
                $this->aboutDocResolved = true;
                $this->aboutDoc = null;

                return;
            }
            try {
                $this->aboutJob = [
                    'git' => $git,
                    'stage' => 'blob',
                    'path' => $entry->path,
                    'process' => $this->reader->startBlobProcess($git, 'HEAD', $entry->path),
                ];
            } catch (\Throwable) {
                $this->aboutDocResolved = true;
                $this->aboutDoc = null;
            }

            return;
        }

        $this->aboutDocResolved = true;
        $this->aboutDoc = $proc->isSuccessful()
            ? ['path' => $job['path'] ?? 'README.md', 'content' => $proc->getOutput()]
            : null;
        $this->aboutCache = null;
        if (null !== $this->aboutDoc) {
            $this->isAboutLoading($this->lastAboutCols);
        }
    }

    private function pollAboutRenderJob(): void
    {
        if (null === $this->aboutRenderJob || $this->aboutRenderJob['process']->isRunning()) {
            return;
        }

        $job = $this->aboutRenderJob;
        $this->aboutRenderJob = null;
        $proc = $job['process'];
        $lines = $proc->isSuccessful()
            ? (preg_split('/\r\n|\r|\n/', rtrim($proc->getOutput(), "\n")) ?: [])
            : $job['fallback'];
        $this->aboutCache = ['key' => $job['key'], 'lines' => $lines];
    }

    private function isAboutLoading(?int $cols = null): bool
    {
        if ($this->aboutDocResolved) {
            if (null === $this->aboutDoc) {
                return false;
            }
            if (null !== $cols) {
                $this->lastAboutCols = $cols;
            } else {
                $cols = $this->lastAboutCols;
            }
            $cacheKey = (string) $cols;
            if (null !== $this->aboutCache && $this->aboutCache['key'] === $cacheKey) {
                return false;
            }
            if (null === $this->renderer || !$this->renderer->isAvailable()) {
                $lines = preg_split('/\r\n|\r|\n/', rtrim($this->aboutDoc['content'], "\n")) ?: [];
                $this->aboutCache = ['key' => $cacheKey, 'lines' => $lines];

                return false;
            }
            if (null === $this->aboutRenderJob || $this->aboutRenderJob['key'] !== $cacheKey) {
                if (null !== $this->aboutRenderJob && $this->aboutRenderJob['process']->isRunning()) {
                    $this->aboutRenderJob['process']->stop(0);
                }
                $fallback = preg_split('/\r\n|\r|\n/', rtrim($this->aboutDoc['content'], "\n")) ?: [];
                try {
                    $this->aboutRenderJob = [
                        'key' => $cacheKey,
                        'process' => $this->renderer->startRenderDocumentProcess($this->aboutDoc['path'], $this->aboutDoc['content'], max(20, $cols - 2)),
                        'fallback' => $fallback,
                    ];
                } catch (\Throwable) {
                    $this->aboutCache = ['key' => $cacheKey, 'lines' => $fallback];

                    return false;
                }
            }

            return true;
        }
        if (null !== $this->aboutJob) {
            return true;
        }
        if (null === $this->reader || null === $this->repoDirectory) {
            $this->aboutDocResolved = true;
            $this->aboutDoc = null;

            return false;
        }
        try {
            $softRepo = $this->repoQuery->get($this->actor, '.soft-serve');
            $git = $this->reader->openRepository($this->repoDirectory->pathFor($softRepo->name()));
            $this->aboutJob = [
                'git' => $git,
                'stage' => 'tree',
                'process' => $this->reader->startTreeProcess($git, 'HEAD', ''),
            ];

            return true;
        } catch (\Throwable) {
            $this->aboutDocResolved = true;
            $this->aboutDoc = null;

            return false;
        }
    }

    public function handleKey(string $seq): bool
    {
        $this->flash = null;

        if ($this->newRepoOpen) {
            return $this->handleModalKey($seq);
        }
        if ($this->fuzzyOpen) {
            return $this->handleFuzzyKey($seq);
        }

        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        switch ($tok) {
            case Keys::CTRL_C:
            case Keys::ESCAPE:
                $this->host->quit();

                return true;
            case Keys::TAB:
                $this->closeFuzzySearch();
                $this->tab = ($this->tab + 1) % \count(self::TABS);

                return true;
            case Keys::BACKTAB:
                $this->closeFuzzySearch();
                $this->tab = ($this->tab + \count(self::TABS) - 1) % \count(self::TABS);

                return true;
            case Keys::UP:
                if (self::TAB_ABOUT === $this->tab) {
                    $this->scrollAbout(-1);
                } else {
                    $this->moveSelection(-1);
                }

                return true;
            case Keys::DOWN:
                if (self::TAB_ABOUT === $this->tab) {
                    $this->scrollAbout(1);
                } else {
                    $this->moveSelection(1);
                }

                return true;
            case Keys::PAGE_UP:
            case Keys::CTRL_U:
            case Keys::CTRL_B:
                if (self::TAB_ABOUT === $this->tab) {
                    $this->scrollAbout(-max(1, $this->aboutRows - 2));
                } else {
                    $this->moveSelection(-10);
                }

                return true;
            case Keys::PAGE_DOWN:
            case Keys::CTRL_D:
            case Keys::CTRL_F:
            case Keys::SPACE:
                if (self::TAB_ABOUT === $this->tab) {
                    $this->scrollAbout(max(1, $this->aboutRows - 2));
                } else {
                    $this->moveSelection(10);
                }

                return true;
            case Keys::HOME:
                if (self::TAB_ABOUT === $this->tab) {
                    $this->aboutScroll = 0;
                } else {
                    $this->selected[$this->tab] = 0;
                }

                return true;
            case Keys::END:
                if (self::TAB_ABOUT === $this->tab) {
                    $this->aboutScroll = \PHP_INT_MAX;
                } else {
                    $this->selected[$this->tab] = max(0, \count($this->currentList()) - 1);
                }

                return true;
            case Keys::ENTER:
                $this->openSelected();

                return true;
            case Keys::RIGHT:
                if (self::TAB_FEED === $this->tab || self::TAB_REPOS === $this->tab) {
                    $this->openSelected();

                    return true;
                }
                break;
            case Keys::CTRL_P:
                if (self::TAB_FEED === $this->tab || self::TAB_REPOS === $this->tab) {
                    $this->openFuzzySearch();

                    return true;
                }
        }
        switch ($txt) {
            case 'q':
                $this->host->quit();

                return true;
            case 'k':
                if (self::TAB_ABOUT === $this->tab) {
                    $this->scrollAbout(-1);
                } else {
                    $this->moveSelection(-1);
                }

                return true;
            case 'j':
                if (self::TAB_ABOUT === $this->tab) {
                    $this->scrollAbout(1);
                } else {
                    $this->moveSelection(1);
                }

                return true;
            case 'u':
                if (self::TAB_ABOUT === $this->tab) {
                    $this->scrollAbout(-(int) round($this->aboutRows / 2));
                }

                return true;
            case 'd':
                if (self::TAB_ABOUT === $this->tab) {
                    $this->scrollAbout((int) round($this->aboutRows / 2));
                }

                return true;
            case 'b':
                if (self::TAB_ABOUT === $this->tab) {
                    $this->scrollAbout(-max(1, $this->aboutRows - 2));
                } else {
                    $this->moveSelection(-10);
                }

                return true;
            case 'f':
                if (self::TAB_ABOUT === $this->tab) {
                    $this->scrollAbout(max(1, $this->aboutRows - 2));
                } else {
                    $this->moveSelection(10);
                }

                return true;
            case 'g':
                if (self::TAB_ABOUT === $this->tab) {
                    $this->aboutScroll = 0;
                } else {
                    $this->selected[$this->tab] = 0;
                }

                return true;
            case 'G':
                if (self::TAB_ABOUT === $this->tab) {
                    $this->aboutScroll = \PHP_INT_MAX;
                } else {
                    $this->selected[$this->tab] = max(0, \count($this->currentList()) - 1);
                }

                return true;
            case 'n':
                $this->openNewRepo();

                return true;
            case '?':
                $this->helpOpen = !$this->helpOpen;

                return true;
            case 'A':
                if ($this->actor?->isAdmin()) {
                    $this->host->navigateToAdmin();
                }

                return true;
            case '/':
                if (self::TAB_FEED === $this->tab || self::TAB_REPOS === $this->tab) {
                    $this->openFuzzySearch();

                    return true;
                }
                break;
            case 'l':
                if (self::TAB_FEED === $this->tab || self::TAB_REPOS === $this->tab) {
                    $this->openSelected();

                    return true;
                }
                break;
        }

        return false;
    }

    private function openFuzzySearch(): void
    {
        $this->fuzzyOpen = true;
        $this->fuzzyQuery = '';
        $this->fuzzySel = 0;
    }

    private function closeFuzzySearch(): void
    {
        $this->fuzzyOpen = false;
        $this->fuzzyQuery = '';
        $this->fuzzySel = 0;
    }

    private function handleFuzzyKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if (Keys::CTRL_C === $tok) {
            $this->host->quit();

            return true;
        }

        if (Keys::ESCAPE === $tok) {
            $this->closeFuzzySearch();

            return true;
        }

        if (Keys::TAB === $tok) {
            $this->closeFuzzySearch();
            $this->tab = ($this->tab + 1) % \count(self::TABS);

            return true;
        }

        if (Keys::BACKTAB === $tok) {
            $this->closeFuzzySearch();
            $this->tab = ($this->tab + \count(self::TABS) - 1) % \count(self::TABS);

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

        $repos = $this->fuzzyRepos($this->currentList());
        $count = \count($repos);

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
            if ($count > 0) {
                $repo = $repos[min($this->fuzzySel, $count - 1)];
                $this->closeFuzzySearch();
                $this->host->navigateToRepo($repo->nameString());
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
     * @param list<Repo> $list
     *
     * @return list<Repo>
     */
    private function fuzzyRepos(array $list): array
    {
        $query = trim($this->fuzzyQuery);
        if ('' === $query) {
            return $list;
        }

        $scored = [];
        foreach ($list as $idx => $repo) {
            $score = AbstractRepoTab::fuzzyScoreFields($query, [
                ['text' => $repo->nameString(), 'weight' => 3],
                ['text' => $repo->projectName(), 'weight' => 3],
                ['text' => $repo->owner()->username(), 'weight' => 2],
                ['text' => $repo->description(), 'weight' => 2],
                ['text' => $this->cloneCommand($repo), 'weight' => 1],
            ]);
            if (null !== $score) {
                $scored[] = ['repo' => $repo, 'score' => $score, 'idx' => $idx];
            }
        }

        usort($scored, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return $a['idx'] <=> $b['idx'];
        });

        return array_map(static fn(array $item): Repo => $item['repo'], $scored);
    }

    private function handleModalKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if (Keys::ESCAPE === $tok) {
            $this->newRepoOpen = false;
        } elseif (Editor::isCtrlS($seq)) {
            $this->submitNewRepo();
        } elseif (Keys::ENTER === $tok) {
            if (2 === $this->field) {
                $this->submitNewRepo();
            } else {
                $this->field = ($this->field + 1) % 3;
            }
        } elseif (Keys::TAB === $tok || Keys::DOWN === $tok) {
            $this->field = ($this->field + 1) % 3;
        } elseif (Keys::BACKTAB === $tok || Keys::UP === $tok) {
            $this->field = ($this->field + 2) % 3;
        } elseif (2 === $this->field && (Keys::SPACE === $tok || Keys::LEFT === $tok || Keys::RIGHT === $tok)) {
            $this->form['private'] = !$this->form['private'];
        } elseif ($this->field < 2 && Keys::BACKSPACE === $tok) {
            $key = 0 === $this->field ? 'name' : 'description';
            $this->form[$key] = mb_substr($this->form[$key], 0, -1);
        } elseif ($this->field < 2 && null !== $txt) {
            $key = 0 === $this->field ? 'name' : 'description';
            $this->form[$key] .= $txt;
        }

        return true;
    }

    private function submitNewRepo(): void
    {
        $name = trim($this->form['name']);
        if ('' === $name) {
            $this->error = 'Name is required.';

            return;
        }

        try {
            $repo = ($this->createRepo)(
                $this->actor,
                $name,
                $this->form['private'],
                false,
                trim($this->form['description']),
                '',
            );
        } catch (InvalidInput | Conflict | AccessDenied $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->newRepoOpen = false;
        $this->form = ['name' => '', 'description' => '', 'private' => false];
        $this->field = 0;
        $this->error = null;
        $this->flash = 'Created ' . $repo->nameString();
        $this->tab = self::TAB_REPOS;
        $this->load();
        $this->selectByName(self::TAB_REPOS, $repo->nameString());
    }

    private function openNewRepo(): void
    {
        if (null === $this->actor) {
            $this->flash = 'Sign in over SSH to create repositories.';

            return;
        }
        $this->newRepoOpen = true;
        $this->form = ['name' => '', 'description' => '', 'private' => false];
        $this->field = 0;
        $this->error = null;
    }

    private function openSelected(): void
    {
        $list = $this->currentList();
        if ([] === $list) {
            return;
        }
        $repo = $list[min($this->selected[$this->tab], \count($list) - 1)] ?? null;
        if (null !== $repo) {
            $this->host->navigateToRepo($repo->nameString());
        }
    }

    private function moveSelection(int $delta): void
    {
        $count = \count($this->currentList());
        if (0 === $count) {
            return;
        }
        $this->selected[$this->tab] = max(0, min($this->selected[$this->tab] + $delta, $count - 1));
    }

    private function selectByName(int $tab, string $name): void
    {
        $list = self::TAB_FEED === $tab ? $this->feed : $this->owned;
        foreach ($list as $i => $repo) {
            if ($repo->nameString() === $name) {
                $this->selected[$tab] = $i;

                return;
            }
        }
    }

    /**
     * @return list<Repo>
     */
    private function currentList(): array
    {
        return match ($this->tab) {
            self::TAB_FEED => $this->feed,
            self::TAB_REPOS => $this->owned,
            default => [],
        };
    }

    protected function onTabBarClick(int $line, int $col): bool
    {
        if (0 !== $line) {
            return false;
        }
        // tabBarDots renders "[• ]label" segments joined by three spaces; walk the
        // visible widths to find the tab under the cursor.
        $pos = 0;
        foreach (self::TABS as $i => $label) {
            $text = ($i === $this->tab ? '• ' : '') . $label;
            $len = Screen::width($text);
            if ($col >= $pos && $col < $pos + $len) {
                if ($i !== $this->tab) {
                    $this->closeFuzzySearch();
                    $this->tab = $i;
                }

                return true;
            }
            $pos += $len + 3; // three-space separator
        }

        return false;
    }

    protected function onBodyClick(int $line, int $col, bool $nav = false): bool
    {
        if ($this->newRepoOpen) {
            $field = null === $this->modalFieldRows ? false : array_search($line, $this->modalFieldRows, true);
            if (false === $field) {
                return false;
            }
            if (2 === $field) {
                if (2 === $this->field) {
                    $this->form['private'] = !$this->form['private'];
                } else {
                    $this->field = 2;
                }
            } else {
                $this->field = $field;
            }

            return true;
        }

        if (!isset($this->listRowItem[$line])) {
            return false;
        }
        $idx = $this->listRowItem[$line];

        if ($this->fuzzyOpen) {
            if ($nav || $idx === $this->fuzzySel) {
                $repo = $this->listDisplayRepos[$idx] ?? null;
                if (null !== $repo) {
                    $this->closeFuzzySearch();
                    $this->host->navigateToRepo($repo->nameString());
                }
            } else {
                $this->fuzzySel = $idx;
            }

            return true;
        }

        $wasSelected = $idx === $this->selected[$this->tab];
        $this->selected[$this->tab] = $idx;
        if ($nav || $wasSelected) {
            $this->openSelected();
        }

        return true;
    }

    protected function headerLines(int $cols): array
    {
        return [' ' . Screen::serverBadge($this->settings->name ?: 'Soft Serve'), ''];
    }

    protected function tabBarLines(int $cols): array
    {
        return [Screen::tabBarDots(self::TABS, $this->tab, $cols), ''];
    }

    protected function footerLines(int $cols): array
    {
        if (null !== $this->flash) {
            return [Screen::helpLine([[trim($this->flash), '']], $cols)];
        }
        if ($this->newRepoOpen) {
            return [Screen::helpLine([['tab/↑/↓', 'field'], ['space', 'toggle'], ['ctrl+s/enter', 'create'], ['esc', 'cancel']], $cols)];
        }
        if ($this->fuzzyOpen) {
            return [Screen::helpLine([
                ['type', 'filter'],
                ['↑/↓', 'move'],
                ['enter', 'open'],
                ['esc', 'close'],
            ], $cols)];
        }
        if ($this->helpOpen) {
            return Screen::helpGrid([
                [['tab', 'section'], ['/', 'search']],
                [['→/l', 'select']],
                [['↑/k', 'up'], ['↓/j', 'down']],
                [['g/home', 'go to start'], ['G/end', 'go to end']],
                [['?', 'toggle help'], ['q', 'quit']],
            ], $cols);
        }

        if (self::TAB_FEED === $this->tab || self::TAB_REPOS === $this->tab) {
            return [Screen::helpLine([
                ['/', 'search'],
                ['↑↓', 'navigate'],
                ['tab', 'section'],
                ['→/l', 'select'],
                ['↑/k', 'up'],
                ['↓/j', 'down'],
                ['q', 'quit'],
                ['?', 'toggle help'],
            ], $cols)];
        }

        return [Screen::helpLine([
            ['↑↓', 'navigate'],
            ['tab', 'section'],
            ['q', 'quit'],
            ['?', 'toggle help'],
        ], $cols)];
    }

    protected function paintBody(int $cols, int $rows): array
    {
        if ($this->newRepoOpen) {
            $modalWidth = min(60, max(20, $cols - 4));
            $modalLines = $this->modalLines($modalWidth);
            $box = Screen::box('New repository', $modalLines, $modalWidth);
            // centerBlock vertical offset; box prepends a top border row, so the
            // field rows (name, description, private) sit at top + 1/2/3.
            $top = max(0, intdiv($rows - \count($box), 2));
            $this->modalFieldRows = [$top + 1, $top + 2, $top + 3];

            return Screen::centerBlock($box, $cols, $rows);
        }
        $this->modalFieldRows = null;
        $this->listRowItem = [];
        $this->listDisplayRepos = [];

        return match ($this->tab) {
            self::TAB_FEED => $this->feedBody($this->feed, 'No recent activity.', $cols, $rows),
            self::TAB_REPOS => $this->feedBody(
                $this->owned,
                null !== $this->actor ? 'No repositories found.' : 'Sign in over SSH to see your repositories.',
                $cols,
                $rows,
            ),
            self::TAB_ABOUT => $this->aboutBody($cols, $rows),
            self::TAB_PROFILE => $this->profileBody($cols),
            default => [],
        };
    }

    /**
     * @param list<Repo> $list
     *
     * @return list<string>
     */
    private function feedBody(array $list, string $empty, int $cols, int $rows): array
    {
        $headerLines = $this->fuzzyOpen ? [Screen::fuzzySearchBar($this->fuzzyQuery, $cols)] : [];
        $bodyRows = max(1, $rows - \count($headerLines));

        $displayList = $this->fuzzyOpen ? $this->fuzzyRepos($list) : $list;
        if ([] === $displayList) {
            $msg = $this->fuzzyOpen ? 'No matching repositories.' : $empty;

            return array_merge($headerLines, $this->messageBody($msg, $cols));
        }

        $rawSel = $this->fuzzyOpen ? $this->fuzzySel : $this->selected[$this->tab];
        $sel = min($rawSel, \count($displayList) - 1);
        $blocks = [];
        foreach ($displayList as $i => $repo) {
            $blocks[] = Screen::repoItem(
                $repo->nameString(),
                trim($repo->description()),
                $this->cloneCommand($repo),
                'Updated ' . $this->relativeTime($repo->updatedAt()),
                $i === $sel,
                $cols,
            );
        }

        $lines = [];
        $blockStart = [];
        /** @var array<int,int> absolute line index in $lines → block (repo) index */
        $lineBlock = [];
        foreach ($blocks as $bi => $block) {
            if ($bi > 0) {
                $lines[] = '';
            }
            $blockStart[$bi] = \count($lines);
            foreach ($block as $l) {
                $lineBlock[\count($lines)] = $bi;
                $lines[] = $l;
            }
        }

        $offset = 0;
        if (\count($lines) > $bodyRows) {
            $offset = max(0, min(($blockStart[$sel] ?? 0) - 1, \count($lines) - $bodyRows));
        }
        $view = \array_slice($lines, $offset, $bodyRows);
        while (\count($view) < $bodyRows) {
            $view[] = Screen::blank($cols);
        }

        // Map each painted body line (header offset included) back to a repo index
        // so clicks hit the right item regardless of scroll offset.
        $this->listDisplayRepos = $displayList;
        $this->listRowItem = [];
        $headerCount = \count($headerLines);
        foreach ($view as $r => $_) {
            $abs = $offset + $r;
            if (isset($lineBlock[$abs])) {
                $this->listRowItem[$headerCount + $r] = $lineBlock[$abs];
            }
        }

        return array_merge($headerLines, $view);
    }

    private function cloneCommand(Repo $repo): string
    {
        $url = $this->settings->sshPublicUrl;
        if ('' === $url) {
            return '';
        }

        return 'git clone ' . rtrim($url, '/') . '/' . $repo->nameString() . '.git';
    }

    private function relativeTime(\DateTimeImmutable $when): string
    {
        $diff = time() - $when->getTimestamp();
        if ($diff < 60) {
            return 'just now';
        }
        foreach ([[31536000, 'year'], [2592000, 'month'], [86400, 'day'], [3600, 'hour'], [60, 'minute']] as [$secs, $unit]) {
            if ($diff >= $secs) {
                $n = intdiv($diff, $secs);

                return $n . ' ' . $unit . (1 === $n ? '' : 's') . ' ago';
            }
        }

        return 'just now';
    }

    /**
     * @return list<string>
     */
    private function aboutBody(int $cols, int $rows): array
    {
        $this->aboutRows = max(1, $rows);
        if ($this->isAboutLoading($cols)) {
            return Screen::spinnerBody($this->spinner, 'Loading README...', $cols);
        }
        $lines = $this->aboutLines($cols);
        $total = \count($lines);
        $maxScroll = max(0, $total - $rows);
        $top = max(0, min($this->aboutScroll, $maxScroll));
        $this->aboutScroll = $top;

        $out = [];
        for ($r = 0; $r < $rows; ++$r) {
            $idx = $top + $r;
            $out[] = $idx < $total ? Screen::fit($lines[$idx], $cols) : Screen::blank($cols);
        }
        if ($rows > 0) {
            $percent = $maxScroll > 0 ? (int) round($top / $maxScroll * 100) : 100;
            $ind = Theme::style(sprintf('☰ %d%%', $percent), Theme::MUTED);
            $out[$rows - 1] = Screen::fit(str_repeat(' ', max(0, $cols - Screen::width($ind) - 1)) . $ind, $cols);
        }

        return array_values($out);
    }

    private function scrollAbout(int $delta): void
    {
        if ($this->isAboutLoading()) {
            return;
        }
        $lines = $this->aboutLines(80);
        $maxScroll = max(0, \count($lines) - $this->aboutRows);
        $this->aboutScroll = max(0, min($this->aboutScroll + $delta, $maxScroll));
    }

    /**
     * @return list<string>
     */
    private function aboutLines(int $cols): array
    {
        $cacheKey = (string) $cols;
        if (null !== $this->aboutCache && $this->aboutCache['key'] === $cacheKey) {
            return $this->aboutCache['lines'];
        }

        $lines = null;
        if (!$this->aboutDocResolved && null !== $this->reader && null !== $this->repoDirectory) {
            try {
                $softRepo = $this->repoQuery->get($this->actor, '.soft-serve');
                $git = $this->reader->openRepository($this->repoDirectory->pathFor($softRepo->name()));
                $this->aboutDoc = $this->reader->readme($git, 'HEAD');
            } catch (\Throwable) {
                $this->aboutDoc = null;
            }
            $this->aboutDocResolved = true;
        }

        if (null !== $this->aboutDoc) {
            if ($this->renderer?->isAvailable()) {
                $ansi = $this->renderer->renderDocument($this->aboutDoc['path'], $this->aboutDoc['content'], max(20, $cols - 2));
                $lines = preg_split('/\r\n|\r|\n/', rtrim($ansi, "\n")) ?: [];
            } else {
                $lines = preg_split('/\r\n|\r|\n/', rtrim($this->aboutDoc['content'], "\n")) ?: [];
            }
        }
        if (null === $lines) {
            $lines = [
                '',
                Theme::style('No readme found.', Theme::MUTED),
                '',
                Theme::style('Create a `.soft-serve` repository and add a README.md file to display readme.', Theme::MUTED),
            ];
        }

        $this->aboutCache = ['key' => $cacheKey, 'lines' => $lines];

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function profileBody(int $cols): array
    {
        if (null === $this->actor) {
            return $this->messageBody('Not signed in (anonymous session).', $cols);
        }

        $lines = [
            '',
            '  Username  ' . $this->actor->username(),
            '  Role      ' . ($this->actor->isAdmin() ? 'administrator' : 'user'),
            '  Keys      ' . \count($this->actor->publicKeys()),
        ];

        return array_map(static fn(string $l): string => Screen::fit($l, $cols), $lines);
    }

    /**
     * @return list<string>
     */
    private function modalLines(int $width = 60): array
    {
        $innerWidth = max(16, $width - 4);
        $lines = [
            $this->fieldRow('Name', $this->form['name'], 0 === $this->field, $innerWidth),
            $this->fieldRow('Description', $this->form['description'], 1 === $this->field, $innerWidth),
            $this->toggleRow('Private', $this->form['private'], 2 === $this->field),
            '',
        ];
        $lines[] = null !== $this->error
            ? "\e[31m! " . $this->error . "\e[0m"
            : Theme::style('Tab/↑/↓: field · Space: toggle · Ctrl+S/Enter: create · Esc: cancel', Theme::MUTED, italic: true);

        return $lines;
    }

    private function fieldRow(string $label, string $value, bool $active, int $maxWidth = 0): string
    {
        $cursor = $active ? '▏' : '';
        $labelPrefix = sprintf('%-12s ', $label);
        $valWidth = max(4, $maxWidth - Screen::width($labelPrefix));
        $valTrunc = $maxWidth > 0 ? Screen::truncate($value, $valWidth) : $value;
        $text = $labelPrefix . $valTrunc . $cursor;

        return $active ? "\e[1m" . $text . "\e[0m" : $text;
    }

    private function toggleRow(string $label, bool $value, bool $active): string
    {
        $text = sprintf('%-12s %s', $label, $value ? '[x]' : '[ ]');

        return $active ? "\e[1m" . $text . '  (space to toggle)' . "\e[0m" : $text;
    }
}

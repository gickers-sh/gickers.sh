<?php

declare(strict_types=1);

namespace App\UI\Tui\Page;

use App\Identity\Domain\Model\User;
use App\Repo\Application\CreateRepo;
use App\Repo\Application\Query\RepoQuery;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoDirectory;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\UI\Tui\Input\Keys;
use App\UI\Tui\Render\Screen;
use App\UI\Tui\Render\TermstrapRenderer;
use App\UI\Tui\Render\Theme;

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
    /** @var array{key: string, lines: list<string>}|null */
    private ?array $aboutCache = null;

    private bool $newRepoOpen = false;
    private bool $helpOpen = false;
    /** @var array{name: string, description: string, private: bool} */
    private array $form = ['name' => '', 'description' => '', 'private' => false];
    private int $field = 0;
    private ?string $error = null;
    private ?string $flash = null;

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
    ) {}

    protected function load(): void
    {
        $this->feed = $this->repoQuery->visibleTo($this->actor);
        $this->owned = null !== $this->actor ? $this->repoQuery->ownedBy($this->actor) : [];
    }

    public function handleKey(string $seq): bool
    {
        $this->flash = null;

        if ($this->newRepoOpen) {
            return $this->handleModalKey($seq);
        }

        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        switch ($tok) {
            case Keys::CTRL_C:
            case Keys::ESCAPE:
                $this->host->quit();

                return true;
            case Keys::TAB:
                $this->tab = ($this->tab + 1) % \count(self::TABS);

                return true;
            case Keys::BACKTAB:
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
                    $this->aboutScroll = PHP_INT_MAX;
                } else {
                    $this->selected[$this->tab] = max(0, \count($this->currentList()) - 1);
                }

                return true;
            case Keys::ENTER:
                $this->openSelected();

                return true;
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
                    $this->aboutScroll = PHP_INT_MAX;
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
        }

        return false;
    }

    private function handleModalKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if (Keys::ESCAPE === $tok) {
            $this->newRepoOpen = false;
        } elseif (Keys::ENTER === $tok) {
            $this->submitNewRepo();
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
            return [Screen::helpLine([['enter', 'create'], ['tab', 'next field'], ['esc', 'cancel']], $cols)];
        }
        if ($this->helpOpen) {
            return Screen::helpGrid([
                [['tab', 'section']],
                [['→/l', 'select']],
                [['↑/k', 'up'], ['↓/j', 'down']],
                [['g/home', 'go to start'], ['G/end', 'go to end']],
                [['?', 'toggle help'], ['q', 'quit']],
            ], $cols);
        }

        if (self::TAB_FEED === $this->tab || self::TAB_REPOS === $this->tab) {
            return [Screen::helpLine([
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
            $box = Screen::box('New repository', $this->modalLines(), min(60, max(20, $cols - 4)));

            return Screen::centerBlock($box, $cols, $rows);
        }

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
        if ([] === $list) {
            return $this->messageBody($empty, $cols);
        }

        $sel = min($this->selected[$this->tab], \count($list) - 1);
        $blocks = [];
        foreach ($list as $i => $repo) {
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
        foreach ($blocks as $bi => $block) {
            if ($bi > 0) {
                $lines[] = '';
            }
            $blockStart[$bi] = \count($lines);
            foreach ($block as $l) {
                $lines[] = $l;
            }
        }

        $offset = 0;
        if (\count($lines) > $rows) {
            $offset = max(0, min(($blockStart[$sel] ?? 0) - 1, \count($lines) - $rows));
        }
        $view = \array_slice($lines, $offset, $rows);
        while (\count($view) < $rows) {
            $view[] = Screen::blank($cols);
        }

        return $view;
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
        if (null !== $this->reader && null !== $this->repoDirectory) {
            try {
                $softRepo = $this->repoQuery->get($this->actor, '.soft-serve');
                $git = $this->reader->openRepository($this->repoDirectory->pathFor($softRepo->name()));
                $doc = $this->reader->readme($git, 'HEAD');
                if (null !== $doc) {
                    if ($this->renderer?->isAvailable()) {
                        $ansi = $this->renderer->renderDocument($doc['path'], $doc['content'], max(20, $cols - 2));
                        $lines = preg_split('/\r\n|\r|\n/', rtrim($ansi, "\n")) ?: [];
                    } else {
                        $lines = preg_split('/\r\n|\r|\n/', rtrim($doc['content'], "\n")) ?: [];
                    }
                }
            } catch (\Throwable) {
                // No readable .soft-serve repository.
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
    private function modalLines(): array
    {
        $lines = [
            $this->fieldRow('Name', $this->form['name'], 0 === $this->field),
            $this->fieldRow('Description', $this->form['description'], 1 === $this->field),
            $this->toggleRow('Private', $this->form['private'], 2 === $this->field),
            '',
        ];
        $lines[] = null !== $this->error
            ? "\e[31m! " . $this->error . "\e[0m"
            : 'Enter: create · Tab: next · Esc: cancel';

        return $lines;
    }

    private function fieldRow(string $label, string $value, bool $active): string
    {
        $cursor = $active ? '▏' : '';
        $text = sprintf('%-12s %s%s', $label, $value, $cursor);

        return $active ? "\e[1m" . $text . "\e[0m" : $text;
    }

    private function toggleRow(string $label, bool $value, bool $active): string
    {
        $text = sprintf('%-12s %s', $label, $value ? '[x]' : '[ ]');

        return $active ? "\e[1m" . $text . '  (space to toggle)' . "\e[0m" : $text;
    }
}

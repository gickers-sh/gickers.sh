<?php

declare(strict_types=1);

namespace App\UI\Tui\Page;

use App\Identity\Domain\Model\User;
use App\Repo\Application\CreateRepo;
use App\Repo\Application\Query\RepoQuery;
use App\Repo\Domain\Model\Repo;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\UI\Tui\Input\Keys;
use App\UI\Tui\Render\Screen;

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
    private const TAB_FEED = 0;
    private const TAB_REPOS = 1;
    private const TAB_ABOUT = 2;
    private const TAB_PROFILE = 3;

    /** @var list<string> */
    private const TABS = ['Feed', 'My Repositories', 'About', 'Profile'];

    private int $tab = self::TAB_FEED;

    /** @var array<int,int> per-tab selected index */
    private array $selected = [0, 0, 0, 0];

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
        if ($this->helpOpen) {
            $this->helpOpen = false;

            return true;
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
                $this->moveSelection(-1);

                return true;
            case Keys::DOWN:
                $this->moveSelection(1);

                return true;
            case Keys::HOME:
                $this->selected[$this->tab] = 0;

                return true;
            case Keys::END:
                $this->selected[$this->tab] = max(0, \count($this->currentList()) - 1);

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
                $this->moveSelection(-1);

                return true;
            case 'j':
                $this->moveSelection(1);

                return true;
            case 'g':
                $this->selected[$this->tab] = 0;

                return true;
            case 'G':
                $this->selected[$this->tab] = max(0, \count($this->currentList()) - 1);

                return true;
            case 'n':
                $this->openNewRepo();

                return true;
            case '?':
                $this->helpOpen = true;

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

    protected function headerText(): string
    {
        return ' codes.sh — ' . ($this->settings->name ?: 'Soft Serve') . ' ';
    }

    protected function tabBarText(): string
    {
        return Screen::tabBar(self::TABS, $this->tab, 200);
    }

    protected function footerText(): string
    {
        if (null !== $this->flash) {
            return ' ' . $this->flash;
        }
        if ($this->newRepoOpen) {
            return ' Enter: create · Tab: next field · Esc: cancel';
        }
        if ($this->helpOpen) {
            return ' Press any key to close';
        }
        $admin = $this->actor?->isAdmin() ? ' · A: admin' : '';

        return ' ↑/↓ move · Enter: open · n: new' . $admin . ' · ?: help · q: quit';
    }

    protected function paintBody(int $cols, int $rows): array
    {
        if ($this->newRepoOpen) {
            $box = Screen::box('New repository', $this->modalLines(), min(60, max(20, $cols - 4)));

            return Screen::centerBlock($box, $cols, $rows);
        }
        if ($this->helpOpen) {
            $box = Screen::box('Keyboard shortcuts', $this->helpLines(), min(52, max(20, $cols - 4)));

            return Screen::centerBlock($box, $cols, $rows);
        }

        return match ($this->tab) {
            self::TAB_FEED => $this->listBody($this->feed, 'No recent activity.', $cols, $rows),
            self::TAB_REPOS => $this->listBody(
                $this->owned,
                null !== $this->actor ? 'No repositories found.' : 'Sign in over SSH to see your repositories.',
                $cols,
                $rows,
            ),
            self::TAB_ABOUT => $this->aboutBody($cols),
            self::TAB_PROFILE => $this->profileBody($cols),
            default => [],
        };
    }

    /**
     * @param list<Repo> $list
     *
     * @return list<string>
     */
    private function listBody(array $list, string $empty, int $cols, int $rows): array
    {
        if ([] === $list) {
            return $this->messageBody($empty, $cols);
        }

        $items = [];
        foreach ($list as $repo) {
            $meta = trim($repo->description());
            if ('' === $meta && $repo->isPrivate()) {
                $meta = 'private';
            }
            $items[] = ['label' => $repo->nameString(), 'meta' => $meta];
        }

        return Screen::listView($items, $this->selected[$this->tab], $rows, $cols);
    }

    /**
     * @return list<string>
     */
    private function aboutBody(int $cols): array
    {
        $lines = [
            '',
            '  ' . ($this->settings->name ?: 'Soft Serve'),
            '',
            '  A self-hostable Git server for the command line.',
            '',
            '  SSH   ' . $this->settings->sshPublicUrl,
            '  HTTP  ' . $this->settings->httpPublicUrl,
        ];

        return array_map(static fn(string $l): string => Screen::fit($l, $cols), $lines);
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

    /**
     * @return list<string>
     */
    private function helpLines(): array
    {
        return [
            '↑/↓ or j/k    Move selection',
            'Enter         Open repository',
            'Tab           Next tab',
            'n             New repository',
            'A             Admin (admins only)',
            '?             Toggle this help',
            'q / Esc       Quit',
            '',
            'Press any key to close.',
        ];
    }
}

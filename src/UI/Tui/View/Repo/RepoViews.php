<?php

declare(strict_types=1);

namespace App\UI\Tui\View\Repo;

use App\Repo\Domain\Git\GitRepository;
use App\Repo\Domain\Model\Repo;
use App\UI\Tui\AsyncLoader;
use App\UI\Tui\Format\Ansi;
use App\UI\Tui\Format\Chrome;
use App\UI\Tui\Navigator;
use App\UI\Tui\Views;
use App\UI\Tui\Widget\ChromeWidget;
use App\UI\Tui\Widget\TabBarWidget;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Everything the views of one open repository share: its {@see RepoState},
 * its {@see RepoData} caches, the app {@see Views} (services, navigation) and
 * the repository flash message. One instance per {@see RepoView}.
 */
final class RepoViews
{
    /** Repository tab labels, in order (`1`…`8`). */
    public const TABS = ['Readme', 'Files', 'Commits', 'Branches', 'Tags', 'Deployments', 'Profile', 'Settings'];

    public readonly RepoState $state;
    public readonly RepoData $data;

    /** One-shot message replacing the status bar until the next key. */
    public ?string $flash = null;

    private ?RepoView $root = null;

    public function __construct(
        public readonly Views $views,
        GitRepository $git,
        ?Repo $db,
    ) {
        $this->state = new RepoState($git, $db, $views->session()->actor, $views->settings->sshPublicUrl);
        $this->data = new RepoData($this->state, $views->reader, $views->async());
    }

    public function navigator(): Navigator
    {
        return $this->views->navigator();
    }

    public function async(): AsyncLoader
    {
        return $this->views->async();
    }

    /**
     * Called once by {@see RepoView}'s constructor.
     */
    public function bindRoot(RepoView $root): void
    {
        $this->root = $root;
    }

    public function root(): RepoView
    {
        return $this->root ?? throw new \LogicException('RepoView not bound.');
    }

    /**
     * Unwind every view pushed above the repository view, then show tab $index.
     */
    public function showTab(int $index): void
    {
        $root = $this->root();
        $this->navigator()->popTo($root);
        $root->selectTab($index);
    }

    /**
     * The repository page frame around $body: repo header, tab bar ($tab active),
     * body and the status bar (`name │ value │ info │ * ref`, or the flash).
     * With $fullScreen the margin, header and tab bar are hidden (old
     * `isFullScreenModal()`), leaving body + status.
     */
    public function frame(RepoScreen $status, AbstractWidget $body, int $tab, bool $fullScreen = false): ChromeWidget
    {
        $tabs = null;
        if (!$fullScreen) {
            $tabs = new TabBarWidget(self::TABS, TabBarWidget::BAR, 1);
            $tabs->setActive($tab);
            $tabs->onSelect(fn (int $i) => $this->showTab($i));
        }
        $chrome = new ChromeWidget($tabs, $body);
        $chrome->setTopMargin(!$fullScreen);
        $chrome->setHeader($fullScreen ? [] : fn (int $cols): array => $this->headerLines($cols));
        $chrome->setStatus(fn (int $cols): array => [$this->statusLine($status, $cols)]);

        return $chrome;
    }

    /**
     * @return list<string>
     */
    public function headerLines(int $cols): array
    {
        $db = $this->state->db;
        $name = $this->state->name.($db?->isPrivate() ? ' 🔒' : '');

        return Chrome::repoHeader($name, $db?->description() ?? '', $this->state->cloneCommand(), $cols);
    }

    public function statusLine(RepoScreen $status, int $cols): string
    {
        if (null !== $this->flash) {
            return "\e[7m".Ansi::fit(' '.$this->flash, $cols)."\e[0m";
        }

        return Chrome::statusBar(
            $cols,
            $this->state->name,
            $status->statusValue(),
            $status->statusInfo(),
            $status->statusBranch() ?? ('* '.$this->state->ref),
        );
    }

    /**
     * Stop all repository-level background work (the view left the stack).
     */
    public function cancel(): void
    {
        $this->async()->cancel($this->data);
    }
}

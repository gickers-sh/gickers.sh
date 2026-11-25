<?php

declare(strict_types=1);

namespace App\UI\Tui;

use App\Access\Application\ServerPolicy;
use App\Deploy\Application\Query\QueryDeployments;
use App\Identity\Domain\UserStore;
use App\Repo\Application\Query\RepoQuery;
use App\Repo\Domain\Git\GitRepository;
use App\Repo\Domain\RepoDirectory;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\UI\Tui\Builder\TuiViewBuilder;
use App\UI\Tui\Render\TermstrapRenderer;
use App\UI\Tui\Render\TwigTuiRenderer;
use App\UI\Tui\View\Common\ConfirmView;
use App\UI\Tui\View\Common\HelpView;
use App\UI\Tui\View\Repo\RepoView;
use App\UI\Tui\View\Repo\RepoViews;
use App\UI\Tui\View\Selection\SelectionView;
use App\UI\Tui\View\View;
use App\Wiki\Application\Query\WikiQuery;
use Psr\Log\LoggerInterface;

/**
 * Per-app factory of root views and the navigation helpers shared by them.
 * Also the service bag views read their use cases and queries from.
 */
final class Views
{
    public function __construct(
        public readonly TuiApp $app,
        public readonly RepoQuery $repoQuery,
        public readonly UserStore $userStore,
        public readonly ServerPolicy $serverPolicy,
        public readonly ServerSettings $settings,
        public readonly GitRepositoryReader $reader,
        public readonly TermstrapRenderer $renderer,
        public readonly RepoDirectory $repoDirectory,
        public readonly ?LoggerInterface $logger = null,
        public readonly ?QueryDeployments $queryDeployments = null,
        public readonly ?WikiQuery $wikiQuery = null,
        public readonly ?TwigTuiRenderer $twigTui = null,
        public readonly ?TuiViewBuilder $builder = null,
    ) {
        if (null !== $this->twigTui) {
            \App\UI\Tui\Format\Rows::setDefaultRenderer($this->twigTui);
            \App\UI\Tui\Format\Chrome::setDefaultRenderer($this->twigTui);
        }
    }

    public function navigator(): Navigator
    {
        return $this->app->navigator();
    }

    public function async(): AsyncLoader
    {
        return $this->app->async();
    }

    public function session(): Session
    {
        return $this->app->session();
    }

    public function selection(): SelectionView
    {
        return new SelectionView($this);
    }

    /**
     * @throws \App\Shared\Domain\Exception\NotFound     authenticated caller, unreadable/absent repo
     * @throws \App\Shared\Domain\Exception\AccessDenied anonymous caller, unreadable/absent repo
     * @throws \RuntimeException                         repository directory missing on disk
     */
    public function repoByName(string $name): RepoView
    {
        $repo = $this->repoQuery->get($this->session()->actor, $name);
        $git = $this->reader->openRepository($this->repoDirectory->pathFor($repo->name()));

        return new RepoView(new RepoViews($this, $git, $repo));
    }

    public function repoForGit(GitRepository $git): RepoView
    {
        return new RepoView(new RepoViews($this, $git, null));
    }

    public function help(View $for): HelpView
    {
        return new HelpView($this->navigator(), $for);
    }

    /**
     * @param list<string>     $body
     * @param \Closure(): void $onYes
     */
    public function confirm(string $title, array $body, \Closure $onYes): ConfirmView
    {
        return new ConfirmView($this->navigator(), $title, $body, $onYes);
    }

    /**
     * Open a repository (by stored name) applying denyRead; stays on the current
     * view with a flash message on failure.
     */
    public function openRepo(string $name): void
    {
        $actor = $this->session()->actor;
        try {
            $view = $this->repoByName($name);
        } catch (\Throwable $e) {
            $this->logger?->warning(\sprintf('TUI: Failed to open repository "%s": %s', $name, $e->getMessage()), [
                'repo' => $name,
                'actor' => $actor?->username(),
                'exception' => $e,
            ]);
            $this->session()->flash = $e instanceof \RuntimeException && str_contains($e->getMessage(), 'does not exist')
                ? \sprintf('Repository "%s" directory does not exist on disk.', $name)
                : \sprintf('Cannot open repository "%s": %s', $name, $e->getMessage());

            return;
        }
        $this->navigator()->reset($view);
    }

    /**
     * Pop the top view; at the root go back to the selection view, or quit when
     * there is none (soft:browse).
     */
    public function back(): void
    {
        $nav = $this->navigator();
        if ($nav->depth() > 1) {
            $nav->pop();

            return;
        }
        if ($this->session()->selectionAvailable && !$nav->top() instanceof SelectionView) {
            $nav->reset($this->selection());

            return;
        }
        $this->app->quit();
    }
}

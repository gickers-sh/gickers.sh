<?php

declare(strict_types=1);

namespace App\UI\Tui;

use App\Access\Application\ServerPolicy;
use App\CodeIntel\Application\Query\CodeIntelQuery;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\PullRequest\Application\MergePullRequest;
use App\PullRequest\Application\ClosePullRequest;
use App\PullRequest\Application\CommentPullRequest;
use App\PullRequest\Application\DeletePullRequest;
use App\PullRequest\Application\OpenPullRequest;
use App\PullRequest\Application\ReopenPullRequest;
use App\PullRequest\Application\UpdatePullRequest;
use App\PullRequest\Application\Query\PullRequestQuery;
use App\Repo\Application\CreateRepo;
use App\Repo\Application\DeleteFile;
use App\Repo\Application\WriteFile;
use App\Repo\Application\Query\RepoQuery;
use App\Repo\Application\UpdateRepoSettings;
use App\Repo\Domain\Git\GitRepository;
use App\Repo\Domain\RepoDirectory;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\UI\Tui\Page\AdminPage;
use App\UI\Tui\Page\RepoPage;
use App\UI\Tui\Page\SelectionPage;
use App\UI\Tui\Render\TermstrapRenderer;
use Symfony\Component\Tui\Tui;

/**
 * Builds the TUI shell ({@see RootUI}) and its pages, wiring each page with the
 * module use cases/queries it needs. The entrypoint commands own the {@see Tui}
 * lifecycle; this factory only constructs the page graph.
 */
final class TuiPageFactory
{
    public function __construct(
        private readonly RepoQuery $repoQuery,
        private readonly CreateRepo $createRepo,
        private readonly UpdateRepoSettings $updateSettings,
        private readonly UserStore $userStore,
        private readonly ServerPolicy $serverPolicy,
        private readonly ServerSettings $settings,
        private readonly GitRepositoryReader $reader,
        private readonly TermstrapRenderer $renderer,
        private readonly CodeIntelQuery $codeIntel,
        private readonly RepoDirectory $repoDirectory,
        private readonly ?PullRequestQuery $pullRequestQuery = null,
        private readonly ?MergePullRequest $mergePullRequest = null,
        private readonly ?WriteFile $writeFile = null,
        private readonly ?DeleteFile $deleteFile = null,
        private readonly ?OpenPullRequest $openPullRequest = null,
        private readonly ?ClosePullRequest $closePullRequest = null,
        private readonly ?ReopenPullRequest $reopenPullRequest = null,
        private readonly ?DeletePullRequest $deletePullRequest = null,
        private readonly ?CommentPullRequest $commentPullRequest = null,
        private readonly ?UpdatePullRequest $updatePullRequest = null,
    ) {}

    public function createRoot(Tui $tui, ?User $actor): RootUI
    {
        return new RootUI($tui, $this, $actor, selectionAvailable: true);
    }

    public function createBrowseRoot(Tui $tui, ?User $actor): RootUI
    {
        return new RootUI($tui, $this, $actor, selectionAvailable: false);
    }

    public function selectionPage(?User $actor): SelectionPage
    {
        return new SelectionPage($this->repoQuery, $this->createRepo, $this->settings, $actor, $this->reader, $this->renderer, $this->repoDirectory);
    }

    /**
     * @throws \App\Shared\Domain\Exception\NotFound     authenticated caller, unreadable/absent repo
     * @throws \App\Shared\Domain\Exception\AccessDenied anonymous caller, unreadable/absent repo
     */
    public function repoPageForName(?User $actor, string $name): RepoPage
    {
        $repo = $this->repoQuery->get($actor, $name);
        $git = $this->reader->openRepository($this->repoDirectory->pathFor($repo->name()));

        return new RepoPage($git, $repo, $actor, $this->reader, $this->renderer, $this->codeIntel, $this->updateSettings, $this->pullRequestQuery, $this->mergePullRequest, $this->writeFile, $this->deleteFile, $this->openPullRequest, $this->closePullRequest, $this->reopenPullRequest, $this->deletePullRequest, $this->commentPullRequest, $this->updatePullRequest, $this->settings->sshPublicUrl);
    }

    public function repoPageForGit(?User $actor, GitRepository $git): RepoPage
    {
        return new RepoPage($git, null, $actor, $this->reader, $this->renderer, $this->codeIntel, $this->updateSettings, $this->pullRequestQuery, $this->mergePullRequest, $this->writeFile, $this->deleteFile, $this->openPullRequest, $this->closePullRequest, $this->reopenPullRequest, $this->deletePullRequest, $this->commentPullRequest, $this->updatePullRequest, $this->settings->sshPublicUrl);
    }

    public function adminPage(?User $actor): AdminPage
    {
        return new AdminPage($this->serverPolicy, $this->userStore, $this->settings, $actor);
    }
}

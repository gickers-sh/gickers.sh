<?php

declare(strict_types=1);

namespace App\Tui\Page;

use App\Model\GitRepositoryModel;
use App\Model\UserModel;
use App\Service\Backend\BackendService;
use App\Service\Git\GitService;
use App\Service\Render\TermstrapRenderer;
use App\Tui\Component\ComponentInterface;
use App\Tui\Event\InputEvent;
use App\Tui\Event\KeyMsg;
use App\Tui\Style\ZoneManager;
use Throwable;

final class RootUI implements ComponentInterface
{
    private SelectionPage $selectionPage;
    private ?RepoPage $repoPage = null;
    private ?AdminPage $adminPage = null;
    private ComponentInterface $activePage;

    public function __construct(
        private readonly GitService $gitService,
        private readonly TermstrapRenderer $renderer,
        private readonly ?BackendService $backend = null,
        private readonly ?UserModel $currentUser = null,
    ) {
        $this->selectionPage = new SelectionPage($this->gitService, $this->renderer, $this->backend, $this->currentUser);
        $this->selectionPage->setOnSelectRepo(function (GitRepositoryModel $repo): void {
            $this->openRepo($repo);
        });
        $this->selectionPage->setOnAdmin(function (): void {
            $this->switchToAdmin();
        });
        $this->activePage = $this->selectionPage;
    }

    public function init(): void
    {
        ZoneManager::clear();
        $this->selectionPage->init();
        $this->activePage = $this->selectionPage;
    }

    public function openRepo(GitRepositoryModel $repo): void
    {
        $this->repoPage = new RepoPage($repo, $this->gitService, $this->renderer, $this->backend, $this->currentUser);
        $this->repoPage->setOnBack(function (): void {
            $this->switchToSelection();
        });
        $this->repoPage->init();
        $this->activePage = $this->repoPage;
    }

    public function openRepoByPath(string $path): void
    {
        if ($this->backend !== null && !is_dir($path)) {
            $candidate = $this->backend->getRepoPath($path);
            if (is_dir($candidate)) {
                $path = $candidate;
            }
        }
        $repo = $this->gitService->openRepository($path);
        $this->openRepo($repo);
    }

    public function switchToSelection(): void
    {
        $this->repoPage = null;
        $this->adminPage = null;
        $this->selectionPage->init();
        $this->activePage = $this->selectionPage;
    }

    public function switchToAdmin(): void
    {
        $this->adminPage = new AdminPage();
        $this->adminPage->setOnBack(function (): void {
            $this->switchToSelection();
        });
        $this->adminPage->init();
        $this->activePage = $this->adminPage;
    }

    public function update(InputEvent $event): ?string
    {
        return $this->activePage->update($event);
    }

    public function view(int $width, int $height): string
    {
        $rawFrame = $this->activePage->view($width, $height);
        // Scan frame to record 2D bounding boxes for mouse interaction and strip sentinels
        return ZoneManager::scan($rawFrame);
    }
}

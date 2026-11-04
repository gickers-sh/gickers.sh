<?php

declare(strict_types=1);

namespace App\UI\Tui\Page;

use App\Model\UserModel;
use App\Repo\Domain\Git\GitRepository;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Service\Backend\BackendService;
use App\UI\Tui\Render\TermstrapRenderer;
use CodesSh\Tui\Component\ComponentInterface;
use CodesSh\Tui\Event\InputEvent;
use CodesSh\Tui\Style\ZoneManager;

final class RootUI implements ComponentInterface
{
    private SelectionPage $selectionPage;
    private ?RepoPage $repoPage = null;
    private ?AdminPage $adminPage = null;
    private ComponentInterface $activePage;

    public function __construct(
        private readonly GitRepositoryReader $gitService,
        private readonly TermstrapRenderer $renderer,
        private readonly ?BackendService $backend = null,
        private readonly ?UserModel $currentUser = null,
    ) {
        $this->selectionPage = new SelectionPage($this->gitService, $this->renderer, $this->backend, $this->currentUser);
        $this->selectionPage->setOnSelectRepo(function (GitRepository $repo): void {
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

    public function openRepo(GitRepository $repo): void
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
        if (null !== $this->backend && !is_dir($path)) {
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

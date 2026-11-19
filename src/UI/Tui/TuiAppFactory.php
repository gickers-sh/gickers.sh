<?php

declare(strict_types=1);

namespace App\UI\Tui;

use App\Access\Application\ServerPolicy;
use App\Deploy\Application\Query\QueryDeployments;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Application\Query\RepoQuery;
use App\Repo\Domain\RepoDirectory;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\UI\Tui\Render\TermstrapRenderer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Tui\Terminal\TerminalInterface;

/**
 * Builds one {@see TuiApp} per terminal session, wiring {@see Views} with the
 * module use cases and queries. The entrypoint commands own the lifecycle.
 */
final class TuiAppFactory
{
    public function __construct(
        private readonly RepoQuery $repoQuery,
        private readonly UserStore $userStore,
        private readonly ServerPolicy $serverPolicy,
        private readonly ServerSettings $settings,
        private readonly GitRepositoryReader $reader,
        private readonly TermstrapRenderer $renderer,
        private readonly RepoDirectory $repoDirectory,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?QueryDeployments $queryDeployments = null,
    ) {
    }

    /**
     * @param bool $selectionAvailable false for soft:browse (no feed: "back" at the root quits)
     */
    public function create(TerminalInterface $terminal, ?User $actor, bool $selectionAvailable): TuiApp
    {
        return new TuiApp(
            $terminal,
            new Session($actor, $selectionAvailable),
            fn (TuiApp $app): Views => new Views(
                $app,
                $this->repoQuery,
                $this->userStore,
                $this->serverPolicy,
                $this->settings,
                $this->reader,
                $this->renderer,
                $this->repoDirectory,
                $this->logger,
                $this->queryDeployments,
            ),
            $this->logger,
        );
    }
}

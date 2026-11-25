<?php

declare(strict_types=1);

namespace App\UI\Tui\View\Repo\Deployments;

use App\Deploy\Domain\Model\DeployEnvironment;
use App\Deploy\Domain\Model\DeployRelease;
use App\UI\Tui\Format\Fuzzy;
use App\UI\Tui\Format\Rows;
use App\UI\Tui\Format\Time;
use App\UI\Tui\View\Repo\RepoScreen;
use App\UI\Tui\View\Repo\RepoViews;
use App\UI\Tui\Widget\FilterableListWidget;
use App\UI\Tui\Widget\ListWidget;
use Symfony\Component\Tui\Input\Key;

/**
 * Intermediate releases list view (`Repo > Deployments > Branch > Release`):
 * uses the exact same two-line accent-bar list style as {@see DeploymentsPane}
 * (`┃ #id (branch · latest)  [status]                                  shortSha`
 *  `┃ Triggered by: @user · Started: ... · Duration: ...`).
 *
 * Selecting a release (`Enter` / `l` / `→`) pushes {@see DeploymentView} (the full
 * deployment display view for that branch and release).
 */
final class DeploymentReleasesView extends RepoScreen
{
    private readonly FilterableListWidget $filterableList;
    private readonly ListWidget $list;
    private ?DeployEnvironment $environment = null;
    /** @var list<DeployRelease> */
    private array $releases = [];
    private bool $loaded = false;

    public function __construct(
        RepoViews $repo,
        private readonly string $refName,
        private readonly int $tab = DeploymentsPane::TAB_INDEX,
    ) {
        parent::__construct($repo);

        $list = new ListWidget(function (mixed $item, bool $selected, int $cols) use (&$list): array {
            if (!$item instanceof DeployRelease || null === $list) {
                return [];
            }

            $first = $list->items()[0] ?? null;
            $prefix = ($item !== $first) ? [''] : [];

            $isLatest = isset($this->releases[0]) && $this->releases[0]->id() === $item->id();
            $shortSha = substr($item->commitSha(), 0, 7);
            $trigger = $item->triggerDisplay();
            $started = $item->startedAt()->format('Y-m-d H:i:s').' ('.Time::relative($item->startedAt()).')';
            $duration = null !== $item->finishedAt()
                ? ($item->finishedAt()->getTimestamp() - $item->startedAt()->getTimestamp()).'s'
                : ('building' === $item->status()->value ? 'running...' : '—');
            $logSummary = self::extractLogSummary($item->buildLog());
            $uptime = ($isLatest && null !== $this->environment && 'running' === $this->environment->status()->value && 'succeeded' === $item->status()->value)
                ? Time::uptime($item->finishedAt() ?? $item->startedAt())
                : '';

            return array_merge(
                $prefix,
                Rows::release(
                    $item->id(),
                    $isLatest,
                    $item->status()->value,
                    $shortSha,
                    $trigger,
                    $started,
                    $duration,
                    $selected,
                    $cols,
                    $this->refName,
                    $item->commitSha(),
                    $logSummary,
                    $uptime,
                ),
            );
        });
        $this->list = $list;

        $this->updateEmptyText(false);

        $this->filterableList = new FilterableListWidget(
            $this->list,
            fn (string $query, array $all): array => $this->filterReleases($query, $all),
        );

        $this->filterableList->onToggle(function (bool $open): void {
            $this->updateEmptyText($open);
        });

        $this->list->onSelect(function (mixed $item): void {
            if ($item instanceof DeployRelease) {
                if ($this->filterableList->isOpen()) {
                    $this->filterableList->close();
                }
                $this->repo->navigator()->push(
                    new DeploymentView($this->repo, $this->refName, $this->tab, $item->id()),
                );
            }
        });

        $this->add($this->repo->frame($this, $this->filterableList, $this->tab));
    }

    public function title(): string
    {
        return 'Releases · '.$this->refName;
    }

    public function shortcuts(): array
    {
        return [
            'back' => ['q', Key::ESCAPE, Key::BACKSPACE, Key::LEFT, 'h'],
            'filter' => ['/', 'ctrl+p'],
            'open' => [Key::RIGHT, 'l'],
            'inspect_env' => ['i'],
            'reload' => ['r'],
        ];
    }

    public function handleShortcut(string $action): bool
    {
        return match ($action) {
            'back' => $this->back(),
            'filter' => $this->openFilter(),
            'open' => $this->openSelectedOrEnv(),
            'inspect_env' => $this->openEnvDisplay(null),
            'reload' => $this->refresh(),
            default => false,
        };
    }

    public function keyHints(): array
    {
        if ($this->filterableList->isOpen()) {
            return [
                ['type', 'filter'],
                ['↑/↓', 'move'],
                ['enter', 'open display'],
                ['esc', 'close'],
            ];
        }

        return [
            ['/', 'search'],
            ['↑↓', 'navigate'],
            ['enter', 'open display'],
            ['r', 'refresh'],
            ['esc', 'back'],
            ['?', 'toggle help'],
        ];
    }

    public function statusValue(): string
    {
        if ($this->filterableList->isOpen()) {
            $q = $this->filterableList->query();

            return '' !== $q ? '/'.$q : '[SEARCH]';
        }

        $selected = $this->list->selected();
        if ($selected instanceof DeployRelease) {
            return sprintf('%s · #%d (%s)', $this->refName, $selected->id(), $selected->status()->value);
        }

        $status = null !== $this->environment ? ' ('.$this->environment->status()->value.')' : '';

        return $this->refName.$status;
    }

    public function statusInfo(): string
    {
        $items = $this->list->items();
        $count = \count($items);
        if (0 === $count) {
            return '';
        }

        return '# '.($this->list->index() + 1).'/'.$count;
    }

    public function refresh(): bool
    {
        $this->loadReleases();
        $this->repo->flash = 'Refreshed releases for '.$this->refName;
        $this->repo->root()->refreshChrome();

        return true;
    }

    protected function onActivate(): void
    {
        if (!$this->loaded) {
            $this->loadReleases();
        }
        $this->focus($this->filterableList->isOpen() ? $this->filterableList->input() : $this->list);
    }

    private function back(): bool
    {
        $this->repo->navigator()->pop();

        return true;
    }

    private function openFilter(): bool
    {
        $this->filterableList->open();

        return true;
    }

    private function openSelectedOrEnv(): bool
    {
        if ([] === $this->list->items()) {
            return $this->openEnvDisplay(null);
        }

        return $this->list->apply('select');
    }

    private function openEnvDisplay(?int $releaseId): bool
    {
        $this->repo->navigator()->push(
            new DeploymentView($this->repo, $this->refName, $this->tab, $releaseId),
        );

        return true;
    }

    private function loadReleases(): void
    {
        $this->loaded = true;
        $db = $this->repo->state->db;
        $query = $this->repo->views->queryDeployments;
        $actor = $this->repo->state->actor;

        if (null === $db || null === $query) {
            $this->repo->flash = 'Deployment releases unavailable.';
            $this->repo->navigator()->pop();

            return;
        }

        try {
            $details = $query->deploymentDetails($actor, $db->name(), $this->refName);
        } catch (\Throwable $e) {
            $this->repo->flash = 'Cannot load deployment releases: '.$e->getMessage();
            $this->repo->navigator()->pop();

            return;
        }

        $this->environment = $details['environment'];
        $this->releases = $details['releases'];
        $this->filterableList->setAll($this->releases);
        $this->updateEmptyText($this->filterableList->isOpen());
        $this->repo->root()->refreshChrome();
    }

    private function updateEmptyText(bool $filterOpen): void
    {
        if ($filterOpen) {
            $this->list->setEmptyText("\n  No matching releases.");
        } else {
            $this->list->setEmptyText("\n  No releases recorded for ".$this->refName.' yet (press i or → to view environment details).');
        }
    }

    /**
     * @param list<mixed> $all
     *
     * @return list<mixed>
     */
    private function filterReleases(string $query, array $all): array
    {
        $q = trim($query);
        if ('' === $q) {
            return $all;
        }

        $scored = [];
        foreach ($all as $idx => $release) {
            if (!$release instanceof DeployRelease) {
                continue;
            }

            $sha = $release->commitSha();
            $trigger = $release->triggerDisplay();

            $score = Fuzzy::fields($q, [
                ['text' => '#'.$release->id(), 'weight' => 3],
                ['text' => (string) $release->id(), 'weight' => 3],
                ['text' => $release->status()->value, 'weight' => 2],
                ['text' => substr($sha, 0, 7), 'weight' => 2],
                ['text' => $sha, 'weight' => 1],
                ['text' => $trigger, 'weight' => 2],
            ]);

            if (null !== $score) {
                $scored[] = ['release' => $release, 'score' => $score, 'idx' => $idx];
            }
        }

        usort($scored, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return $a['idx'] <=> $b['idx'];
        });

        return array_map(static fn (array $item): DeployRelease => $item['release'], $scored);
    }

    private static function extractLogSummary(string $buildLog): string
    {
        $trimmed = trim($buildLog);
        if ('' === $trimmed) {
            return '';
        }

        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $trimmed) ?: []),
            static fn (string $line): bool => '' !== $line,
        ));

        if ([] === $lines) {
            return '';
        }

        // Prefer the last "[error] ..." line if present, otherwise the last non-empty step/output line
        for ($i = \count($lines) - 1; $i >= 0; --$i) {
            if (str_contains($lines[$i], '[error]')) {
                return $lines[$i];
            }
        }

        return $lines[\count($lines) - 1];
    }
}

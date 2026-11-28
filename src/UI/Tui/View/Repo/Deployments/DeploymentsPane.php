<?php

declare(strict_types=1);

namespace App\UI\Tui\View\Repo\Deployments;

use App\Deploy\Domain\DeployQuota;
use App\Deploy\Domain\Model\DeployDomain;
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
 * Full-fledged "Deployments" repository tab (tab 6): lists deployment environments
 * with fuzzy filtering (`/`) and opens {@see DeploymentView} on selection (`Enter` / `l` / `→`).
 */
final class DeploymentsPane extends RepoScreen
{
    public const TAB_INDEX = 5;

    private readonly FilterableListWidget $filterableList;
    private readonly ListWidget $list;
    private bool $loaded = false;

    public function __construct(RepoViews $repo)
    {
        parent::__construct($repo);

        $list = new ListWidget(static function (mixed $item, bool $selected, int $cols) use (&$list): array {
            if (!\is_array($item) || !isset($item['environment'], $item['quota']) || null === $list) {
                return [];
            }

            /** @var DeployEnvironment $env */
            $env = $item['environment'];
            /** @var DeployQuota $quota */
            $quota = $item['quota'];
            /** @var ?DeployRelease $latest */
            $latest = $item['latestRelease'] ?? null;
            /** @var list<DeployRelease> $releases */
            $releases = $item['releases'] ?? [];

            $first = $list->items()[0] ?? null;
            $prefix = ($item !== $first) ? [''] : [];

            $shortSha = null !== $env->deployedSha() ? substr($env->deployedSha(), 0, 7) : 'none';
            $domains = array_map(
                static fn (DeployDomain $d): string => sprintf(
                    '%s://%s -> %s:%d',
                    $d->isTlsEnabled() ? 'https' : 'http',
                    $d->domain(),
                    $d->serviceName(),
                    $d->targetPort(),
                ),
                $env->domains(),
            );
            $domLine = [] !== $domains ? 'Domains: '.implode(', ', $domains) : 'Domains: No custom domains configured';

            $envVarsCount = \count($env->envVars());
            $uptime = self::computeUptime($env, $latest);
            $uptimeSegment = '' !== $uptime ? 'Uptime: '.$uptime : 'Uptime: — ('.$env->status()->value.')';
            $quotaLine = sprintf(
                '%s · Quota: %s CPU, %dMB RAM, %d PIDs, max %d services · Env vars: %d · Network: %s',
                $uptimeSegment,
                (string) $quota->cpus,
                $quota->memoryMb,
                $quota->pids,
                $quota->maxServices,
                $envVarsCount,
                $env->networkName(),
            );

            if (null !== $latest) {
                $trigger = $latest->triggerDisplay();
                $duration = null !== $latest->finishedAt()
                    ? ($latest->finishedAt()->getTimestamp() - $latest->startedAt()->getTimestamp()).'s'
                    : ('building' === $latest->status()->value ? 'running...' : '—');
                $releaseLine = sprintf(
                    'Latest release: #%d [%s] by %s · %s (build %s) · %d recent run%s',
                    $latest->id(),
                    $latest->status()->value,
                    $trigger,
                    Time::relative($latest->startedAt()),
                    $duration,
                    \count($releases),
                    1 === \count($releases) ? '' : 's',
                );
            } else {
                $releaseLine = 'Latest release: No releases recorded yet · Updated '.Time::relative($env->updatedAt());
            }

            return array_merge(
                $prefix,
                Rows::deployment(
                    $env->refName(),
                    $env->refType()->value,
                    $env->status()->value,
                    $env->isEnabled(),
                    $shortSha,
                    [$domLine, $quotaLine, $releaseLine],
                    $selected,
                    $cols,
                    $uptime,
                ),
            );
        });

        $this->list = $list;
        $this->updateEmptyText(false);

        $this->filterableList = new FilterableListWidget(
            $this->list,
            fn (string $query, array $all): array => $this->filterDeployments($query, $all),
        );

        $this->filterableList->onToggle(function (bool $open): void {
            $this->updateEmptyText($open);
        });

        $this->list->onSelect(function (mixed $item): void {
            if (\is_array($item) && isset($item['environment']) && $item['environment'] instanceof DeployEnvironment) {
                if ($this->filterableList->isOpen()) {
                    $this->filterableList->close();
                }
                $this->repo->navigator()->push(
                    new DeploymentReleasesView($this->repo, $item['environment']->refName(), self::TAB_INDEX),
                );
            }
        });

        $this->add($this->filterableList);
    }

    public function title(): string
    {
        return 'Deployments';
    }

    public function shortcuts(): array
    {
        return [
            'filter' => ['/', 'ctrl+p'],
            'open' => [Key::RIGHT, 'l'],
            'reload' => ['r'],
        ];
    }

    public function handleShortcut(string $action): bool
    {
        return match ($action) {
            'filter' => $this->openFilter(),
            'open' => $this->list->apply('select'),
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
                ['enter', 'open'],
                ['esc', 'close'],
            ];
        }

        return [
            ['/', 'search'],
            ['↑↓', 'navigate'],
            ['enter', 'inspect'],
            ['r', 'refresh'],
            ['tab', 'section'],
            ['q', 'quit'],
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
        if (\is_array($selected) && isset($selected['environment']) && $selected['environment'] instanceof DeployEnvironment) {
            return $selected['environment']->refName().' ('.$selected['environment']->status()->value.')';
        }

        return '';
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
        $this->loadDeployments();
        $this->repo->flash = 'Refreshed deployments';
        $this->repo->root()->refreshChrome();

        return true;
    }

    public function resetNavigation(): void
    {
        if ($this->filterableList->isOpen()) {
            $this->filterableList->close();
        }
        $this->list->setIndex(0);
    }

    protected function onActivate(): void
    {
        if (!$this->loaded) {
            $this->loadDeployments();
        }
        $this->focus($this->filterableList->isOpen() ? $this->filterableList->input() : $this->list);
    }

    private function openFilter(): bool
    {
        $this->filterableList->open();

        return true;
    }

    private function loadDeployments(): void
    {
        $this->loaded = true;
        $db = $this->repo->state->db;
        $query = $this->repo->views->queryDeployments;
        $actor = $this->repo->state->actor;

        if (null === $db || null === $query) {
            $this->filterableList->setAll([]);
            $this->updateEmptyText($this->filterableList->isOpen());

            return;
        }

        try {
            $items = $query->environmentsForRepo($actor, $db->name());
        } catch (\Throwable) {
            $items = [];
        }

        $this->filterableList->setAll($items);
        $this->updateEmptyText($this->filterableList->isOpen());
        $this->repo->root()->refreshChrome();
    }

    private function updateEmptyText(bool $filterOpen): void
    {
        if ($filterOpen) {
            $this->list->setEmptyText("\n  No matching deployments.");

            return;
        }

        if (null === $this->repo->state->db) {
            $this->list->setEmptyText("\n  Local repository (deployments are not available).");

            return;
        }

        $query = $this->repo->views->queryDeployments;
        if (null === $query) {
            $this->list->setEmptyText("\n  Deployments are not enabled on this server.");

            return;
        }

        if (!$query->isDeploySupported()) {
            $this->list->setEmptyText("\n  Deployment engine is disabled (Docker not detected on host).");

            return;
        }

        try {
            $query->environmentsForRepo($this->repo->state->actor, $this->repo->state->db->name());
            $this->list->setEmptyText("\n  No deployment environments configured.");
        } catch (\Throwable) {
            $this->list->setEmptyText("\n  Write or administrator access is required to view deployments.");
        }
    }

    /**
     * @param list<mixed> $all
     *
     * @return list<mixed>
     */
    private function filterDeployments(string $query, array $all): array
    {
        $q = trim($query);
        if ('' === $q) {
            return $all;
        }

        $scored = [];
        foreach ($all as $idx => $item) {
            if (!\is_array($item) || !isset($item['environment']) || !$item['environment'] instanceof DeployEnvironment) {
                continue;
            }
            $env = $item['environment'];
            $sha = $env->deployedSha() ?? '';
            $domainsText = implode(' ', array_map(
                static fn (DeployDomain $d): string => $d->domain().' '.$d->serviceName(),
                $env->domains(),
            ));

            $score = Fuzzy::fields($q, [
                ['text' => $env->refName(), 'weight' => 3],
                ['text' => $env->status()->value, 'weight' => 2],
                ['text' => $env->refType()->value, 'weight' => 2],
                ['text' => $domainsText, 'weight' => 2],
                ['text' => substr($sha, 0, 7), 'weight' => 2],
                ['text' => $sha, 'weight' => 1],
            ]);

            if (null !== $score) {
                $scored[] = ['item' => $item, 'score' => $score, 'idx' => $idx];
            }
        }

        usort($scored, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return $a['idx'] <=> $b['idx'];
        });

        return array_map(static fn (array $entry): mixed => $entry['item'], $scored);
    }

    public static function computeUptime(DeployEnvironment $env, ?DeployRelease $latest): string
    {
        if ('running' !== $env->status()->value) {
            return '';
        }

        if (null !== $latest && 'succeeded' === $latest->status()->value) {
            $since = $latest->finishedAt() ?? $latest->startedAt();

            return Time::uptime($since);
        }

        return Time::uptime($env->updatedAt());
    }
}

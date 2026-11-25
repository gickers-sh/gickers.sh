<?php

declare(strict_types=1);

namespace App\UI\Tui\View\Repo\Deployments;

use App\Deploy\Domain\DeployQuota;
use App\Deploy\Domain\Model\DeployDomain;
use App\Deploy\Domain\Model\DeployEnvironment;
use App\Deploy\Domain\Model\DeployEnvVar;
use App\Deploy\Domain\Model\DeployRelease;
use App\UI\Tui\Format\Ansi;
use App\UI\Tui\Format\Chrome;
use App\UI\Tui\Format\Time;
use App\UI\Tui\Render\Theme;
use App\UI\Tui\View\Repo\RepoScreen;
use App\UI\Tui\View\Repo\RepoViews;
use App\UI\Tui\Widget\ScrollWidget;
use Symfony\Component\Tui\Input\Key;

/**
 * Detailed display view (`Repo > Deployments > Branch > Release > Display`) for a
 * deployment environment and its selected release: renders environment metadata,
 * resource quotas, custom domains, masked environment variables, SSH CLI commands
 * (wrapped without truncation), release details, build logs, and container runtime logs
 * inside a standard scrollable viewport ({@see ScrollWidget}).
 */
final class DeploymentView extends RepoScreen
{
    private readonly ScrollWidget $scroll;
    private ?int $selectedReleaseId;
    /** @var array{
     *     environment: DeployEnvironment,
     *     quota: DeployQuota,
     *     domains: list<DeployDomain>,
     *     envVars: list<DeployEnvVar>,
     *     releases: list<DeployRelease>,
     *     activeRelease: ?DeployRelease,
     *     runtimeLogs: string,
     * }|null
     */
    private ?array $details = null;
    private int $lastCols = 0;

    public function __construct(
        RepoViews $repo,
        private readonly string $refName,
        int $tab = DeploymentsPane::TAB_INDEX,
        ?int $releaseId = null,
    ) {
        parent::__construct($repo);
        $this->selectedReleaseId = $releaseId;

        $this->scroll = new ScrollWidget();
        $this->add($this->repo->frame($this, $this->scroll, $tab));
    }

    public function title(): string
    {
        return 'Deployment '.$this->refName;
    }

    public function shortcuts(): array
    {
        return [
            'back' => ['q', Key::ESCAPE, Key::BACKSPACE, Key::LEFT, 'h'],
            'reload' => ['r'],
            'prev_release' => ['['],
            'next_release' => [']'],
        ];
    }

    public function handleShortcut(string $action): bool
    {
        return match ($action) {
            'back' => $this->back(),
            'reload' => $this->reload(),
            'prev_release' => $this->cycleRelease(-1),
            'next_release' => $this->cycleRelease(1),
            default => false,
        };
    }

    public function keyHints(): array
    {
        return [
            ['↑/↓', 'scroll'],
            ['f/b', 'page down/up'],
            ['g/G', 'top/bottom'],
            ['[/]', 'prev/next release'],
            ['r', 'refresh'],
            ['esc', 'back to releases'],
        ];
    }

    public function statusValue(): string
    {
        if (null === $this->details) {
            return $this->refName;
        }

        $env = $this->details['environment'];
        $active = $this->details['activeRelease'];
        $relLabel = null !== $active ? ' · #'.$active->id() : '';

        return $env->refName().' ('.$env->status()->value.')'.$relLabel;
    }

    public function statusInfo(): string
    {
        if (null === $this->details) {
            return '';
        }

        $active = $this->details['activeRelease'];
        if (null !== $active) {
            return 'sha '.substr($active->commitSha(), 0, 7);
        }

        $env = $this->details['environment'];
        $sha = null !== $env->deployedSha() ? substr($env->deployedSha(), 0, 7) : 'none';

        return 'sha '.$sha;
    }

    public function beforeRender(): void
    {
        $cols = max(40, $this->repo->views->app->tui()->getTerminal()->getColumns() - 4);
        if ($cols !== $this->lastCols && null !== $this->details) {
            $this->lastCols = $cols;
            $this->renderDetails();
        }
    }

    protected function onActivate(): void
    {
        if (null === $this->details) {
            $this->loadDetails();
        }
        $this->focus($this->scroll);
    }

    private function back(): bool
    {
        $this->repo->navigator()->pop();

        return true;
    }

    private function reload(): bool
    {
        $this->loadDetails();
        $this->repo->flash = 'Refreshed deployment '.$this->refName;
        $this->repo->root()->refreshChrome();

        return true;
    }

    private function cycleRelease(int $dir): bool
    {
        if (null === $this->details || \count($this->details['releases']) <= 1) {
            return false;
        }

        $releases = $this->details['releases'];
        $activeId = $this->details['activeRelease']?->id();
        $currentIndex = 0;
        foreach ($releases as $i => $rel) {
            if ($rel->id() === $activeId) {
                $currentIndex = $i;
                break;
            }
        }

        $count = \count($releases);
        $nextIndex = ($currentIndex + $dir) % $count;
        if ($nextIndex < 0) {
            $nextIndex += $count;
        }

        $target = $releases[$nextIndex];
        $this->selectedReleaseId = $target->id();
        $this->loadDetails();
        $this->repo->flash = sprintf('Viewing Release #%d (%s)', $target->id(), $target->status()->value);
        $this->repo->root()->refreshChrome();

        return true;
    }

    private function loadDetails(): void
    {
        $db = $this->repo->state->db;
        $query = $this->repo->views->queryDeployments;
        $actor = $this->repo->state->actor;

        if (null === $db || null === $query) {
            $this->repo->flash = 'Deployment details unavailable.';
            $this->repo->navigator()->pop();

            return;
        }

        try {
            $details = $query->deploymentDetails($actor, $db->name(), $this->refName, $this->selectedReleaseId);
        } catch (\Throwable $e) {
            $this->repo->flash = 'Cannot load deployment: '.$e->getMessage();
            $this->repo->navigator()->pop();

            return;
        }

        $this->details = $details;
        $this->lastCols = max(40, $this->repo->views->app->tui()->getTerminal()->getColumns() - 4);
        $this->renderDetails();
        $this->repo->root()->refreshChrome();
    }

    private function renderDetails(): void
    {
        if (null === $this->details) {
            return;
        }

        $cols = $this->lastCols;
        $env = $this->details['environment'];
        $quota = $this->details['quota'];
        $domains = $this->details['domains'];
        $envVars = $this->details['envVars'];
        $releases = $this->details['releases'];
        $activeRelease = $this->details['activeRelease'];
        $runtimeLogs = $this->details['runtimeLogs'];

        // Use the full available width so commands, domains, and SHA values are never artificially cut off
        $cardWidth = max(40, $cols - 2);
        $innerWidth = max(32, $cardWidth - 4);
        $keyWidth = 18;
        $valMaxWidth = max(16, $innerWidth - $keyWidth - 3);

        /**
         * Renders a key-value pair, wrapping long values onto aligned continuation lines
         * instead of truncating them.
         *
         * @return list<string>
         */
        $renderKV = static function (string $key, string $val, ?string $valColor = Theme::FG) use ($keyWidth, $valMaxWidth): array {
            $keyPadded = Theme::style(Ansi::fit($key, $keyWidth), Theme::MUTED);
            $indent = str_repeat(' ', $keyWidth);
            $chunks = Ansi::wrap($val, $valMaxWidth);
            $out = [];
            foreach ($chunks as $i => $chunk) {
                $prefix = 0 === $i ? '  '.$keyPadded.' ' : '  '.$indent.' ';
                $out[] = $prefix.Theme::style($chunk, $valColor);
            }

            return $out;
        };

        $statusColor = match ($env->status()->value) {
            'running' => Theme::GREEN,
            'failed' => Theme::SELECTOR,
            'building', 'queued' => Theme::HASH,
            default => Theme::MUTED,
        };
        $statusBadge = Chrome::badge(strtoupper($env->status()->value), Theme::BG, $statusColor);
        $enabledBadge = $env->isEnabled()
            ? Chrome::badge('ENABLED', Theme::FG, Theme::BORDER2)
            : Chrome::badge('DISABLED', Theme::MUTED, Theme::BORDER);

        $shaFull = $env->deployedSha() ?? 'none';
        $shaShort = null !== $env->deployedSha() ? substr($env->deployedSha(), 0, 7) : 'none';
        $latestRelease = $releases[0] ?? null;
        $uptime = DeploymentsPane::computeUptime($env, $latestRelease);
        $uptimeBadge = '' !== $uptime
            ? ' '.Chrome::badge('UP '.strtoupper($uptime), Theme::BG, Theme::PROPERTY)
            : '';

        $titleSuffix = null !== $activeRelease ? ' · Release #'.$activeRelease->id() : '';

        $lines = [
            '',
            '  '.Theme::style('Deployment Environment · '.$env->refName().$titleSuffix, Theme::BLUE, bold: true).'  '.$statusBadge.' '.$enabledBadge.$uptimeBadge,
            '',
            ...$renderKV('Environment', $env->refName().' ('.$env->refType()->value.')'),
            ...$renderKV('Git Reference', $env->fullGitRef()),
            ...$renderKV('Status', $env->status()->value, $statusColor),
            ...$renderKV(
                'Uptime',
                '' !== $uptime ? $uptime.' (running since '.($latestRelease?->finishedAt() ?? $env->updatedAt())->format('Y-m-d H:i:s').')' : '— (not running)',
                '' !== $uptime ? Theme::PROPERTY : Theme::MUTED,
            ),
            ...$renderKV('Enabled', $env->isEnabled() ? 'Yes' : 'No'),
            ...$renderKV('Active Commit', 'none' !== $shaShort ? $shaShort.' ('.$shaFull.')' : 'none', Theme::HASH),
            ...$renderKV('Project Slug', $env->projectSlug()),
            ...$renderKV('Docker Network', $env->networkName()),
            ...$renderKV('Created', $env->createdAt()->format('Y-m-d H:i:s').' ('.Time::relative($env->createdAt()).')'),
            ...$renderKV('Updated', $env->updatedAt()->format('Y-m-d H:i:s').' ('.Time::relative($env->updatedAt()).')'),
        ];

        if (null !== $activeRelease) {
            $relStatus = $activeRelease->status()->value;
            $relColor = match ($relStatus) {
                'succeeded' => Theme::GREEN,
                'failed' => Theme::SELECTOR,
                default => Theme::HASH,
            };
            $isLatest = isset($releases[0]) && $releases[0]->id() === $activeRelease->id();
            $trigger = $activeRelease->triggerDisplay();
            $started = $activeRelease->startedAt()->format('Y-m-d H:i:s').' ('.Time::relative($activeRelease->startedAt()).')';
            $duration = null !== $activeRelease->finishedAt()
                ? ($activeRelease->finishedAt()->getTimestamp() - $activeRelease->startedAt()->getTimestamp()).'s'
                : ('building' === $relStatus ? 'running...' : '—');
            $relShaFull = $activeRelease->commitSha();
            $relShaShort = substr($relShaFull, 0, 7);

            $lines[] = '';
            $lines[] = '  '.Theme::style('Selected Release · #'.$activeRelease->id().($isLatest ? ' (latest)' : ''), Theme::BLUE, bold: true);
            array_push($lines, ...$renderKV('Release ID', '#'.$activeRelease->id().($isLatest ? ' [latest]' : '')));
            array_push($lines, ...$renderKV('Release Status', $relStatus, $relColor));
            array_push($lines, ...$renderKV('Release Commit', $relShaShort.' ('.$relShaFull.')', Theme::HASH));
            array_push($lines, ...$renderKV('Triggered By', $trigger));
            array_push($lines, ...$renderKV('Started At', $started));
            array_push($lines, ...$renderKV('Duration', $duration));
        }

        $lines = [
            ...$lines,
            '',
            '  '.Theme::style('Resource Quota', Theme::BLUE, bold: true),
            ...$renderKV('CPU Limit', sprintf('%s CPU', (string) $quota->cpus)),
            ...$renderKV('Memory Limit', sprintf('%d MB RAM (%s)', $quota->memoryMb, $quota->memoryDockerString())),
            ...$renderKV('PIDs Limit', sprintf('%d PIDs', $quota->pids)),
            ...$renderKV('Max Services', sprintf('%d services (max %d envs)', $quota->maxServices, $quota->maxEnvironments)),
            '',
            '  '.Theme::style(sprintf('Domains (%d)', \count($domains)), Theme::BLUE, bold: true),
        ];

        if ([] === $domains) {
            $lines[] = '    '.Theme::style('No custom domains configured.', Theme::MUTED, italic: true);
        } else {
            foreach ($domains as $d) {
                $tls = $d->isTlsEnabled() ? 'https' : 'http';
                $dns = null !== $d->dnsVerifiedAt() ? 'DNS verified' : 'DNS unverified';
                $smoke = null !== $d->lastSmokeStatus() ? 'HTTP '.$d->lastSmokeStatus() : 'no smoke test';
                $target = sprintf('%s://%s -> %s:%d (%s, %s)', $tls, $d->domain(), $d->serviceName(), $d->targetPort(), $dns, $smoke);
                array_push($lines, ...$renderKV($d->domain(), $target, Theme::CYAN));
            }
        }

        $lines[] = '';
        $lines[] = '  '.Theme::style(sprintf('Environment Variables (%d)', \count($envVars)), Theme::BLUE, bold: true);
        if ([] === $envVars) {
            $lines[] = '    '.Theme::style('No environment variables set.', Theme::MUTED, italic: true);
        } else {
            foreach ($envVars as $ev) {
                array_push($lines, ...$renderKV($ev->name(), $ev->name().'=•••••••• (updated '.Time::relative($ev->updatedAt()).')', Theme::PROPERTY));
            }
        }

        $sshPrefix = $this->sshPrefix();
        $repoName = $this->repo->state->name;
        $lines[] = '';
        $lines[] = '  '.Theme::style('Manage via SSH CLI', Theme::BLUE, bold: true);
        array_push($lines, ...$renderKV('Redeploy', sprintf('%s repo deploy redeploy %s %s', $sshPrefix, $repoName, $env->refName()), Theme::HIGHLIGHT));
        array_push($lines, ...$renderKV('Logs', sprintf('%s repo deploy logs %s %s', $sshPrefix, $repoName, $env->refName()), Theme::HIGHLIGHT));
        array_push($lines, ...$renderKV('Restart', sprintf('%s repo deploy restart %s %s', $sshPrefix, $repoName, $env->refName()), Theme::HIGHLIGHT));
        array_push($lines, ...$renderKV('Stop', sprintf('%s repo deploy stop %s %s', $sshPrefix, $repoName, $env->refName()), Theme::HIGHLIGHT));

        $lines[] = '';
        if (null !== $activeRelease) {
            $lines[] = '  '.Theme::style(
                sprintf(
                    'Build Log · Release #%d (%s) — %s [%s]',
                    $activeRelease->id(),
                    substr($activeRelease->commitSha(), 0, 7),
                    $activeRelease->startedAt()->format('Y-m-d H:i:s'),
                    $activeRelease->status()->value,
                ),
                Theme::BLUE,
                bold: true,
            );
            $buildLog = trim($activeRelease->buildLog());
            if ('' === $buildLog) {
                $lines[] = '    '.Theme::style('No build logs available for this release.', Theme::MUTED, italic: true);
            } else {
                foreach (preg_split('/\r\n|\r|\n/', $buildLog) ?: [] as $logLine) {
                    $logColor = str_contains($logLine, '[error]')
                        ? Theme::SELECTOR
                        : (str_starts_with($logLine, '==>') ? Theme::CYAN : Theme::FG_DIM);
                    foreach (Ansi::wrap($logLine, max(16, $innerWidth - 4), '  ') as $wrappedLog) {
                        $lines[] = '    '.Theme::style($wrappedLog, $logColor);
                    }
                }
            }
        } else {
            $lines[] = '  '.Theme::style('Build Log', Theme::BLUE, bold: true);
            $lines[] = '    '.Theme::style('No build logs available.', Theme::MUTED, italic: true);
        }

        $lines[] = '';
        $lines[] = '  '.Theme::style('Container Runtime Logs', Theme::BLUE, bold: true);
        $cleanRuntime = trim($runtimeLogs);
        if ('' === $cleanRuntime) {
            $lines[] = '    '.Theme::style('No container runtime logs available.', Theme::MUTED, italic: true);
        } else {
            foreach (preg_split('/\r\n|\r|\n/', $cleanRuntime) ?: [] as $rtLine) {
                foreach (Ansi::wrap($rtLine, max(16, $innerWidth - 4), '  ') as $wrappedRt) {
                    $lines[] = '    '.Theme::style($wrappedRt, Theme::FG_DIM);
                }
            }
        }
        $lines[] = '';

        $boxed = Chrome::box('', $lines, $cardWidth);
        $this->scroll->setLines($boxed);
    }

    private function sshPrefix(): string
    {
        $raw = trim($this->repo->views->settings->sshPublicUrl);
        if ('' === $raw) {
            return 'ssh codes.sh';
        }

        $url = str_contains($raw, '://') ? $raw : 'ssh://'.$raw;
        $parsed = parse_url($url);
        if (false === $parsed || !isset($parsed['host'])) {
            return 'ssh '.$raw;
        }

        $host = $parsed['host'];
        $port = isset($parsed['port']) && 22 !== (int) $parsed['port'] ? (int) $parsed['port'] : null;
        $user = $parsed['user'] ?? null;

        $target = null !== $user && '' !== $user ? "{$user}@{$host}" : $host;

        return null !== $port ? "ssh -p {$port} {$target}" : "ssh {$target}";
    }
}

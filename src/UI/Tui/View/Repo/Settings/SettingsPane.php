<?php

declare(strict_types=1);

namespace App\UI\Tui\View\Repo\Settings;

use App\UI\Tui\Format\Ansi;
use App\UI\Tui\Format\Chrome;
use App\UI\Tui\Format\Time;
use App\UI\Tui\Render\Theme;
use App\UI\Tui\View\Repo\RepoScreen;
use App\UI\Tui\View\Repo\RepoViews;
use App\UI\Tui\Widget\LinesWidget;

final class SettingsPane extends RepoScreen
{
    public function __construct(RepoViews $repo)
    {
        parent::__construct($repo);

        $this->add(new LinesWidget(function (int $cols): array {
            $db = $this->repo->state->db;
            if (null === $db) {
                return [
                    '',
                    '  Local repository (not registered on this server).',
                    '  Name         ' . $this->repo->state->name,
                    '  Default ref  ' . $this->repo->state->git->defaultBranch,
                ];
            }

            $cardWidth = min(74, max(28, $cols - 4));
            $innerWidth = max(20, $cardWidth - 6);
            $admin = $this->canEditSettings();

            $badge = $admin
                ? Chrome::badge('ADMIN', Theme::FG, Theme::HIGHLIGHT)
                : Chrome::badge('READ-ONLY', Theme::MUTED2, Theme::BORDER);
            $header = '  ' . Theme::style('Repository settings', Theme::BLUE, bold: true) . '  ' . $badge;

            $keyWidth = 14;
            $renderKV = static function (string $key, string $val, bool $isSpecial = false) use ($keyWidth, $innerWidth): string {
                $keyPadded = Theme::style(Ansi::fit($key, $keyWidth), Theme::MUTED);
                if ($isSpecial) {
                    return '  ' . $keyPadded . ' ' . $val;
                }

                return '  ' . $keyPadded . ' ' . Theme::style(Ansi::truncate($val, $innerWidth - $keyWidth - 3), Theme::FG);
            };

            $lines = [
                '',
                $header,
                '',
                $renderKV('Name', $db->nameString()),
                $renderKV('Project', '' !== $db->projectName() ? $db->projectName() : '(none)'),
                $renderKV('Default ref', $this->repo->state->git->defaultBranch),
                $renderKV('Updated', Time::relative($db->updatedAt())),
                '',
                Theme::style('  Description', Theme::BLUE, bold: true),
            ];

            $desc = trim($db->description());
            if ('' === $desc) {
                $lines[] = '    ' . Theme::style('(no description)', Theme::MUTED, italic: true);
            } else {
                foreach (explode("\n", $desc) as $dl) {
                    $lines[] = '    ' . Theme::style(Ansi::truncate($dl, $innerWidth - 6), Theme::FG);
                }
            }

            $lines[] = '';
            $lines[] = Theme::style('  Visibility & Type', Theme::BLUE, bold: true);
            $lines[] = $renderKV('Visibility', $db->isPrivate() ? 'Private (restricted)' : 'Public');
            $lines[] = $renderKV('Mirror', $db->isMirror() ? 'Yes' : 'No');
            $lines[] = $renderKV('Hidden', $db->isHidden() ? 'Yes' : 'No');

            if ($admin && null !== $this->repo->views->queryDeployments) {
                $deployments = $this->repo->views->queryDeployments->buildEnvironmentsSummary($db);
                $lines[] = '';
                $lines[] = Theme::style('  Docker Deployments', Theme::BLUE, bold: true);
                if ([] === $deployments) {
                    $lines[] = '    ' . Theme::style('(no deployment environments configured)', Theme::MUTED, italic: true);
                } else {
                    foreach ($deployments as $item) {
                        $env = $item['environment'];
                        $sha = null !== $env->deployedSha() ? substr($env->deployedSha(), 0, 7) : 'none';
                        $domains = array_map(static fn ($d): string => $d->domain(), $env->domains());
                        $domStr = [] !== $domains ? ' [' . implode(', ', $domains) . ']' : '';
                        $val = sprintf('%s (%s) sha=%s%s', $env->status()->value, $env->refType()->value, $sha, $domStr);
                        $lines[] = $renderKV($env->refName(), $val);
                    }
                }
            }
            $lines[] = '';

            $hint = $admin
                ? 'administrator'
                : 'read-only (write access required)';
            $lines[] = '  ' . Theme::style($hint, Theme::MUTED, italic: true);

            return Chrome::box('', $lines, $cardWidth);
        }));
    }

    public function title(): string
    {
        return 'Settings';
    }

    public function keyHints(): array
    {
        return [
            ['tab', 'next'],
            ['1–8', 'jump'],
            ['q', 'back'],
        ];
    }

    public function statusValue(): string
    {
        if (null === $this->repo->state->db) {
            return '';
        }

        return $this->canEditSettings() ? 'admin' : 'read-only';
    }

    public function statusInfo(): string
    {
        return '';
    }

    private function canEditSettings(): bool
    {
        return null !== $this->repo->state->db && null !== $this->repo->state->actor;
    }
}

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

            if (null !== $this->repo->views->twigTui) {
                return $this->repo->views->twigTui->renderLines('@tui/pages/user/repo/settings/index.html.twig', [
                    'repo_name' => $this->repo->state->name,
                    'is_private' => $db->isPrivate(),
                    'default_branch' => $this->repo->state->git->defaultBranch,
                    'clone_url' => $this->repo->state->cloneCommand(),
                    'description' => $db->description(),
                    'is_admin' => $this->canEditSettings(),
                    'project' => $db->projectName(),
                ], $cols);
            }

            $cardWidth = max(28, $cols - 2);
            $innerWidth = max(20, $cardWidth - 4);
            $admin = $this->canEditSettings();

            $badge = $admin
                ? Chrome::badge('ADMIN', Theme::FG, Theme::HIGHLIGHT)
                : Chrome::badge('READ-ONLY', Theme::MUTED2, Theme::BORDER);
            $header = '  ' . Theme::style('Repository settings', Theme::BLUE, bold: true) . '  ' . $badge;

            $keyWidth = 14;
            $valMaxWidth = max(12, $innerWidth - $keyWidth - 3);
            $renderKV = static function (string $key, string $val) use ($keyWidth, $valMaxWidth): array {
                $keyPadded = Theme::style(Ansi::fit($key, $keyWidth), Theme::MUTED);
                $indent = str_repeat(' ', $keyWidth);
                $out = [];
                foreach (Ansi::wrap($val, $valMaxWidth) as $i => $chunk) {
                    $prefix = 0 === $i ? '  ' . $keyPadded . ' ' : '  ' . $indent . ' ';
                    $out[] = $prefix . Theme::style($chunk, Theme::FG);
                }

                return $out;
            };

            $lines = [
                '',
                $header,
                '',
                ...$renderKV('Name', $db->nameString()),
                ...$renderKV('Project', '' !== $db->projectName() ? $db->projectName() : '(none)'),
                ...$renderKV('Default ref', $this->repo->state->git->defaultBranch),
                ...$renderKV('Updated', Time::relative($db->updatedAt())),
                '',
                Theme::style('  Description', Theme::BLUE, bold: true),
            ];

            $desc = trim($db->description());
            if ('' === $desc) {
                $lines[] = '    ' . Theme::style('(no description)', Theme::MUTED, italic: true);
            } else {
                foreach (explode("\n", $desc) as $dl) {
                    foreach (Ansi::wrap($dl, max(12, $innerWidth - 6)) as $wLine) {
                        $lines[] = '    ' . Theme::style($wLine, Theme::FG);
                    }
                }
            }

            $lines[] = '';
            $lines[] = Theme::style('  Visibility & Type', Theme::BLUE, bold: true);
            array_push($lines, ...$renderKV('Visibility', $db->isPrivate() ? 'Private (restricted)' : 'Public'));
            array_push($lines, ...$renderKV('Mirror', $db->isMirror() ? 'Yes' : 'No'));
            array_push($lines, ...$renderKV('Hidden', $db->isHidden() ? 'Yes' : 'No'));
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

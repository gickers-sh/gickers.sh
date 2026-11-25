<?php

declare(strict_types=1);

namespace App\UI\Tui\View\Repo\Profile;

use App\UI\Tui\Format\Ansi;
use App\UI\Tui\Format\Chrome;
use App\UI\Tui\Format\Size;
use App\UI\Tui\Format\Time;
use App\UI\Tui\Render\Theme;
use App\UI\Tui\View\Repo\RepoScreen;
use App\UI\Tui\View\Repo\RepoViews;
use App\UI\Tui\Widget\LinesWidget;

final class ProfilePane extends RepoScreen
{
    public function __construct(RepoViews $repo)
    {
        parent::__construct($repo);

        $this->add(new LinesWidget(function (int $cols): array {
            $db = $this->repo->state->db;
            if (null === $db) {
                return Chrome::messageBody('No profile available for this repository.', $cols);
            }

            $owner = $db->owner();
            if (null !== $this->repo->views->twigTui) {
                return $this->repo->views->twigTui->renderLines('@tui/pages/user/profile/index.html.twig', [
                    'user' => $owner,
                    'repo_count' => 1,
                ], $cols);
            }

            $keys = \count($owner->publicKeys());
            $role = $owner->isAdmin() ? 'admin' : 'user';

            $out = [
                Theme::style('@'.$owner->username(), Theme::BLUE, bold: true),
                Theme::style('1 repository · '.$keys.' SSH key'.(1 === $keys ? '' : 's').' · '.$role, Theme::MUTED, italic: true),
                '',
            ];

            $bar = Theme::style('┃', Theme::HIGHLIGHT);
            $name = Theme::style($db->nameString(), Theme::BLUE, bold: true);
            $updated = Theme::style('Updated '.Time::relative($db->updatedAt()), Theme::MUTED);
            $pad = max(1, $cols - 2 - Ansi::width($name) - Ansi::width($updated));
            $out[] = $bar.' '.$name.str_repeat(' ', $pad).$updated;

            $desc = trim($db->description());
            if ('' !== $desc) {
                foreach (Ansi::wrap($desc, max(10, $cols - 2)) as $wLine) {
                    $out[] = $bar.' '.Theme::style($wLine, Theme::FG_DIM);
                }
            }

            $clone = $this->repo->state->cloneCommand();
            if ('' !== $clone) {
                foreach (Ansi::wrap($clone, max(10, $cols - 2)) as $wLine) {
                    $out[] = $bar.' '.Theme::style($wLine, Theme::SELECTOR);
                }
            }

            $usage = sprintf('Clones: %d · Fetches: %d', $db->cloneCount(), $db->fetchCount());
            $disk = $this->repo->data->diskUsageBytes();
            if (null !== $disk) {
                $usage .= ' · '.Size::human($disk);
            }
            foreach (Ansi::wrap($usage, max(10, $cols - 2)) as $wLine) {
                $out[] = $bar.' '.Theme::style($wLine, Theme::MUTED);
            }

            return $out;
        }));
    }

    public function title(): string
    {
        return 'Profile';
    }

    public function statusValue(): string
    {
        return $this->repo->state->db?->owner()->username() ?? '';
    }

    public function statusInfo(): string
    {
        return null === $this->repo->state->db ? '' : '# 1/1';
    }
}

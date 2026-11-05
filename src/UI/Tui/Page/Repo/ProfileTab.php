<?php

declare(strict_types=1);

namespace App\UI\Tui\Page\Repo;

use App\UI\Tui\Render\Screen;
use App\UI\Tui\Render\Theme;

/**
 * Profile tab: the repository owner's handle, key/role stats and the repository
 * rendered as a selector item (bordered bar, name, description, clone command).
 *
 * Mirrors Go `pkg/ui/pages/repo/profile.go`.
 */
final class ProfileTab extends AbstractRepoTab
{
    public function handleKey(string $seq): bool
    {
        return false;
    }

    public function statusValue(): string
    {
        return $this->ctx->db?->owner()->username() ?? '';
    }

    public function statusInfo(): string
    {
        return null === $this->ctx->db ? '' : '# 1/1';
    }

    public function paintBody(int $cols, int $rows): array
    {
        $repo = $this->ctx->db;
        if (null === $repo) {
            return $this->messageBody('No profile available for this repository.', $cols);
        }

        $owner = $repo->owner();
        $keys = \count($owner->publicKeys());
        $role = $owner->isAdmin() ? 'admin' : 'user';

        $out = [
            Theme::style('@'.$owner->username(), Theme::BLUE, bold: true),
            Theme::style('1 repository · '.$keys.' SSH key'.(1 === $keys ? '' : 's').' · '.$role, Theme::MUTED, italic: true),
            '',
        ];

        $bar = Theme::style('┃', Theme::HIGHLIGHT);
        $name = Theme::style($repo->nameString(), Theme::BLUE, bold: true);
        $updated = Theme::style('Updated '.$this->relativeTime($repo->updatedAt()->getTimestamp()), Theme::MUTED);
        $pad = max(1, $cols - 2 - Screen::width($name) - Screen::width($updated));
        $out[] = $bar.' '.$name.str_repeat(' ', $pad).$updated;

        $desc = trim($repo->description());
        if ('' !== $desc) {
            $out[] = $bar.' '.Theme::style(Screen::truncate($desc, $cols - 2), Theme::FG_DIM);
        }

        $clone = $this->ctx->cloneCommand();
        if ('' !== $clone) {
            $out[] = $bar.' '.Theme::style(Screen::truncate($clone, $cols - 2), Theme::SELECTOR);
        }

        return $out;
    }
}

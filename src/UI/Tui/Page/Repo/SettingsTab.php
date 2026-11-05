<?php

declare(strict_types=1);

namespace App\UI\Tui\Page\Repo;

use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Domain\Exception\NotFound;
use App\UI\Tui\Input\Keys;
use App\UI\Tui\Render\Screen;
use App\UI\Tui\Render\Theme;

/**
 * Settings tab: shows repository metadata and, with write access, an inline
 * editor for the description and private flag backed by
 * {@see \App\Repo\Application\UpdateRepoSettings}. Read-only for local repos.
 */
final class SettingsTab extends AbstractRepoTab
{
    private bool $settingsEditing = false;
    /** @var array{description: string, private: bool} */
    private array $settingsForm = ['description' => '', 'private' => false];
    private int $settingsField = 0;

    public function __construct(RepoContext $ctx)
    {
        parent::__construct($ctx);
        if (null !== $ctx->db) {
            $this->settingsForm = ['description' => $ctx->db->description(), 'private' => $ctx->db->isPrivate()];
        }
    }

    public function capturesInput(): bool
    {
        return $this->settingsEditing;
    }

    public function onActivate(): void
    {
        parent::onActivate();
        $this->settingsEditing = false;
    }

    public function footerText(): string
    {
        if ($this->settingsEditing) {
            return ' Enter: save · Tab: next · Esc: cancel';
        }

        return self::BASE_FOOTER;
    }

    public function handleKey(string $seq): bool
    {
        if ($this->settingsEditing) {
            return $this->handleSettingsKey($seq);
        }

        $txt = Keys::text($seq);
        if ('e' === $txt && $this->canEditSettings()) {
            $this->settingsEditing = true;
            $this->settingsField = 0;
            $this->settingsForm = [
                'description' => $this->ctx->db?->description() ?? '',
                'private' => $this->ctx->db?->isPrivate() ?? false,
            ];

            return true;
        }

        return false;
    }

    private function handleSettingsKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if (Keys::ESCAPE === $tok) {
            $this->settingsEditing = false;
        } elseif (Keys::ENTER === $tok) {
            $this->saveSettings();
        } elseif (Keys::TAB === $tok || Keys::DOWN === $tok) {
            $this->settingsField = ($this->settingsField + 1) % 2;
        } elseif (Keys::BACKTAB === $tok || Keys::UP === $tok) {
            $this->settingsField = ($this->settingsField + 1) % 2;
        } elseif (1 === $this->settingsField && (Keys::SPACE === $tok || Keys::LEFT === $tok || Keys::RIGHT === $tok)) {
            $this->settingsForm['private'] = !$this->settingsForm['private'];
        } elseif (0 === $this->settingsField && Keys::BACKSPACE === $tok) {
            $this->settingsForm['description'] = mb_substr($this->settingsForm['description'], 0, -1);
        } elseif (0 === $this->settingsField && null !== $txt) {
            $this->settingsForm['description'] .= $txt;
        }

        return true;
    }

    private function saveSettings(): void
    {
        if (null === $this->ctx->db) {
            $this->settingsEditing = false;

            return;
        }
        try {
            ($this->ctx->updateSettings)(
                $this->ctx->actor,
                $this->ctx->db->name(),
                $this->settingsForm['description'],
                null,
                $this->settingsForm['private'],
                null,
            );
        } catch (AccessDenied|NotFound|Conflict|InvalidInput $e) {
            $this->ctx->flash = $e->getMessage();

            return;
        }
        $this->settingsEditing = false;
        $this->ctx->flash = 'Settings saved.';
    }

    private function canEditSettings(): bool
    {
        return null !== $this->ctx->db && null !== $this->ctx->actor;
    }

    public function paintBody(int $cols, int $rows): array
    {
        if (null === $this->ctx->db) {
            return array_map(static fn (string $l): string => Screen::fit($l, $cols), [
                '',
                '  Local repository (not registered on this server).',
                '  Name         '.$this->ctx->name,
                '  Default ref  '.$this->ctx->git->defaultBranch,
            ]);
        }

        if ($this->settingsEditing) {
            $lines = [
                '',
                $this->editRow('Description', $this->settingsForm['description'], 0 === $this->settingsField),
                $this->toggleRow('Private', $this->settingsForm['private'], 1 === $this->settingsField),
            ];

            return array_map(static fn (string $l): string => Screen::fit($l, $cols), $lines);
        }

        $db = $this->ctx->db;
        $label = static fn (string $t): string => Theme::style(Screen::fit($t, 13), Theme::MUTED);
        $admin = $this->canEditSettings();

        $projectName = $db->projectName();
        $description = $db->description();
        $type = $db->isMirror() ? 'Mirror' : (null !== $db->forkOf() ? 'Fork' : 'Standard');

        $inner = [
            '',
            '  '.Theme::style('Repository settings', Theme::BLUE, bold: true)
                .($admin ? '  '.Screen::badge('ADMIN', Theme::FG, Theme::HIGHLIGHT) : ''),
            '',
            '  '.Theme::style('General Information', Theme::BLUE, bold: true),
            '  '.$label('Name').Theme::style($this->ctx->name, Theme::FG),
            '  '.$label('Project name').('' !== $projectName
                ? Theme::style($projectName, Theme::FG)
                : Theme::style('(none)', Theme::MUTED, italic: true)),
            '  '.$label('Description').('' !== $description
                ? Theme::style($description, Theme::FG)
                : Theme::style('(none)', Theme::MUTED, italic: true)),
            '',
            '  '.Theme::style('Visibility & Type', Theme::BLUE, bold: true),
            '  '.$label('Access').($db->isPrivate()
                ? Theme::style('○ Private', Theme::HASH)
                : Theme::style('○ Public', Theme::GREEN)),
            '  '.$label('Listing').($db->isHidden()
                ? Theme::style('○ Hidden', Theme::MUTED)
                : Theme::style('○ Visible', Theme::GREEN)),
            '  '.$label('Type').Theme::style($type, Theme::MUTED),
            '',
            '  '.Theme::style($admin ? 'press e to edit settings' : 'read-only (write access required)', Theme::MUTED, italic: true),
        ];

        return Screen::box('', $inner, max(40, $cols - 4));
    }

    public function statusValue(): string
    {
        return $this->ctx->actor?->username() ?? '';
    }

    private function editRow(string $label, string $value, bool $active): string
    {
        $text = sprintf('  %-12s %s%s', $label, $value, $active ? '▏' : '');

        return $active ? "\e[1m".$text."\e[0m" : $text;
    }

    private function toggleRow(string $label, bool $value, bool $active): string
    {
        $text = sprintf('  %-12s %s', $label, $value ? '[x]' : '[ ]');

        return $active ? "\e[1m".$text.'  (space)'."\e[0m" : $text;
    }
}

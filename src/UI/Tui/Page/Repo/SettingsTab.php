<?php

declare(strict_types=1);

namespace App\UI\Tui\Page\Repo;

use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Domain\Exception\NotFound;
use App\UI\Tui\Input\Keys;
use App\UI\Tui\Render\Editor;
use App\UI\Tui\Render\Screen;
use App\UI\Tui\Render\Theme;

/**
 * Settings tab: shows repository metadata and, with write access, an inline
 * editor for the description and private flag backed by
 * {@see \App\Repo\Application\UpdateRepoSettings}. Read-only for local repos.
 */
final class SettingsTab extends AbstractRepoTab
{
    private const FIELD_PROJECT_NAME = 0;
    private const FIELD_DESCRIPTION = 1;
    private const FIELD_PRIVATE = 2;
    private const FIELD_HIDDEN = 3;
    private const FIELD_COUNT = 4;

    private bool $settingsEditing = false;
    /** @var array{projectName: string, description: string, private: bool, hidden: bool} */
    private array $settingsForm = [
        'projectName' => '',
        'description' => '',
        'private' => false,
        'hidden' => false,
    ];
    private int $settingsField = self::FIELD_PROJECT_NAME;
    private ?string $error = null;

    public function __construct(RepoContext $ctx)
    {
        parent::__construct($ctx);
        $this->syncFormFromRepo();
    }

    public function capturesInput(): bool
    {
        return $this->settingsEditing;
    }

    public function onActivate(): void
    {
        parent::onActivate();
        $this->settingsEditing = false;
        $this->error = null;
        $this->syncFormFromRepo();
    }

    public function footerText(): string
    {
        if ($this->settingsEditing) {
            return ' tab/↓ move • space/enter toggle • ctrl+s save • esc cancel';
        }

        return self::BASE_FOOTER;
    }

    public function statusValue(): string
    {
        if (null === $this->ctx->db) {
            return '';
        }

        if ($this->canEditSettings()) {
            return $this->settingsEditing ? 'settings [EDIT]' : 'admin';
        }

        return 'read-only';
    }

    public function handleKey(string $seq): bool
    {
        if ($this->settingsEditing) {
            return $this->handleSettingsKey($seq);
        }

        $txt = Keys::text($seq);
        if ('e' === $txt && $this->canEditSettings()) {
            $this->settingsEditing = true;
            $this->settingsField = self::FIELD_PROJECT_NAME;
            $this->error = null;
            $this->syncFormFromRepo();

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
            $this->error = null;
            $this->syncFormFromRepo();

            return true;
        }

        if (Editor::isCtrlS($seq)) {
            $lines = explode("\n", $this->settingsForm['description']);
            if (\count($lines) > 5) {
                $this->error = 'La description ne peut pas dépasser 5 lignes';

                return true;
            }
            $this->saveSettings();

            return true;
        }

        if (Keys::TAB === $tok || Keys::DOWN === $tok) {
            $this->settingsField = ($this->settingsField + 1) % self::FIELD_COUNT;

            return true;
        }

        if (Keys::BACKTAB === $tok || Keys::UP === $tok) {
            $this->settingsField = ($this->settingsField + self::FIELD_COUNT - 1) % self::FIELD_COUNT;

            return true;
        }

        if (self::FIELD_PRIVATE === $this->settingsField || self::FIELD_HIDDEN === $this->settingsField) {
            if (Keys::SPACE === $tok || Keys::ENTER === $tok || ' ' === $txt || 'x' === $txt || 'X' === $txt || Keys::LEFT === $tok || Keys::RIGHT === $tok) {
                $key = self::FIELD_PRIVATE === $this->settingsField ? 'private' : 'hidden';
                $this->settingsForm[$key] = !$this->settingsForm[$key];

                return true;
            }

            return true;
        }

        // Project name field
        if (self::FIELD_PROJECT_NAME === $this->settingsField) {
            if (Keys::ENTER === $tok) {
                $this->settingsField = self::FIELD_DESCRIPTION;

                return true;
            }
            if (Keys::BACKSPACE === $tok) {
                $this->settingsForm['projectName'] = mb_substr($this->settingsForm['projectName'], 0, -1);

                return true;
            }
            if (null !== $txt) {
                $this->settingsForm['projectName'] .= $txt;

                return true;
            }

            return true;
        }

        // Description field
        if (self::FIELD_DESCRIPTION === $this->settingsField) {
            // Check for newline shortcuts: Shift+Enter or Ctrl+J
            $isNewline = false;
            if ("\x0a" === $seq) {
                $isNewline = true;
            } elseif ("\e" === ($seq[0] ?? '')) {
                $parsed = Keys::isRelease($seq) ? null : (new \Symfony\Component\Tui\Input\KeyParser())->parse($seq);
                $k = $parsed['key'] ?? null;
                if ('shift+enter' === $k || 'ctrl+j' === $k) {
                    $isNewline = true;
                }
            }

            if ($isNewline) {
                $this->settingsForm['description'] .= "\n";
                $lines = explode("\n", $this->settingsForm['description']);
                if (\count($lines) > 5) {
                    $this->error = 'La description ne peut pas dépasser 5 lignes';
                } else {
                    $this->error = null;
                }

                return true;
            }

            if (Keys::ENTER === $tok) {
                $this->settingsField = self::FIELD_PRIVATE;

                return true;
            }

            if (Keys::BACKSPACE === $tok) {
                $this->settingsForm['description'] = mb_substr($this->settingsForm['description'], 0, -1);
                $lines = explode("\n", $this->settingsForm['description']);
                if (\count($lines) <= 5 && 'La description ne peut pas dépasser 5 lignes' === $this->error) {
                    $this->error = null;
                }

                return true;
            }

            if (null !== $txt) {
                $this->settingsForm['description'] .= $txt;

                return true;
            }

            return true;
        }

        return true;
    }

    private function syncFormFromRepo(): void
    {
        if (null !== $this->ctx->db) {
            $this->settingsForm = [
                'projectName' => $this->ctx->db->projectName(),
                'description' => $this->ctx->db->description(),
                'private' => $this->ctx->db->isPrivate(),
                'hidden' => $this->ctx->db->isHidden(),
            ];
        }
    }

    private function saveSettings(): void
    {
        if (null === $this->ctx->db) {
            $this->settingsEditing = false;
            $this->error = null;

            return;
        }

        try {
            ($this->ctx->updateSettings)(
                $this->ctx->actor,
                $this->ctx->db->name(),
                $this->settingsForm['description'],
                $this->settingsForm['projectName'],
                $this->settingsForm['private'],
                $this->settingsForm['hidden'],
            );
        } catch (AccessDenied|NotFound|Conflict|InvalidInput $e) {
            $this->error = $e->getMessage();
            $this->ctx->flash = $e->getMessage();

            return;
        }

        $this->settingsEditing = false;
        $this->error = null;
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

        $cardWidth = min(74, max(28, $cols - 4));
        $innerWidth = max(20, $cardWidth - 6);

        if ($this->settingsEditing) {
            return $this->paintEditingCard($cardWidth, $innerWidth, $cols);
        }

        return $this->paintOverviewCard($cardWidth, $innerWidth, $cols);
    }

    /**
     * @return list<string>
     */
    private function paintEditingCard(int $cardWidth, int $innerWidth, int $cols): array
    {
        $badge = Screen::badge('EDITING', '#1a1b26', '#ff9e64', bold: true);
        $header = '  '.Theme::style('Repository settings', Theme::BLUE, bold: true).'  '.$badge;

        $fieldHeader = function (int $field, string $name): string {
            if ($this->settingsField === $field) {
                return '  '.Theme::style('› '.$name, Theme::BLUE, bold: true);
            }

            return '    '.Theme::style($name, Theme::MUTED);
        };

        $toggleRow = function (int $field, string $label, bool $checked, string $description) use ($innerWidth): string {
            $active = $this->settingsField === $field;
            $cursor = $active ? Theme::style('› ', Theme::BLUE, bold: true) : '  ';
            $box = $checked ? '[x]' : '[ ]';
            $boxSt = $active ? Theme::style($box, Theme::BLUE, bold: true) : $box;
            $labelSt = $active ? Theme::style($label, Theme::BLUE, bold: true) : Theme::style($label, Theme::MUTED);

            $prefix = sprintf('  %s%s %s', $cursor, $boxSt, $labelSt);
            if ('' !== $description) {
                $avail = $innerWidth - Screen::width($prefix) - 2;
                if ($avail > 10) {
                    $prefix .= '  '.Theme::style(Screen::truncate($description, $avail), Theme::MUTED, italic: true);
                }
            }

            return $prefix;
        };

        $descHint = self::FIELD_DESCRIPTION === $this->settingsField
            ? Theme::style(' (shift+enter/ctrl+j: new line, max 5 lines)', Theme::MUTED, italic: true)
            : '';

        $hint = 'tab/↓ move • space/enter toggle • ctrl+s save • esc cancel';
        if (Screen::width($hint) > $innerWidth) {
            $hint = 'tab move • space toggle • ctrl+s save • esc cancel';
        }
        if (Screen::width($hint) > $innerWidth) {
            $hint = 'tab next • space toggle • ctrl+s save';
        }

        $lines = [
            '',
            $header,
            '',
            $fieldHeader(self::FIELD_PROJECT_NAME, 'Project name'),
            '    > '.Screen::truncate($this->settingsForm['projectName'], max(10, $innerWidth - 8)).(self::FIELD_PROJECT_NAME === $this->settingsField ? '▌' : ''),
            '',
            $fieldHeader(self::FIELD_DESCRIPTION, 'Description').$descHint,
        ];

        $descLines = explode("\n", $this->settingsForm['description']);
        foreach ($descLines as $i => $dl) {
            $isLast = $i === \count($descLines) - 1;
            $cursor = (self::FIELD_DESCRIPTION === $this->settingsField && $isLast) ? '▌' : '';
            $lines[] = '    > '.Screen::truncate($dl, max(10, $innerWidth - 8)).$cursor;
        }
        if (null !== $this->error && '' !== $this->error) {
            $lines[] = '    '."\e[31;1m".$this->error."\e[0m";
        }

        $lines[] = '';
        $lines[] = '  '.Theme::style('Visibility & Access', Theme::BLUE, bold: true);
        $lines[] = $toggleRow(self::FIELD_PRIVATE, 'Private', $this->settingsForm['private'], '(restricted to authorized collaborators)');
        $lines[] = $toggleRow(self::FIELD_HIDDEN, 'Hidden', $this->settingsForm['hidden'], '(hidden from public repository lists)');
        $lines[] = '';
        $lines[] = '  '.Theme::style($hint, Theme::MUTED, italic: true);

        return Screen::box('', $lines, $cardWidth);
    }

    /**
     * @return list<string>
     */
    private function paintOverviewCard(int $cardWidth, int $innerWidth, int $cols): array
    {
        $db = $this->ctx->db;
        $admin = $this->canEditSettings();

        $badge = $admin
            ? Screen::badge('ADMIN', Theme::FG, Theme::HIGHLIGHT)
            : Screen::badge('READ-ONLY', Theme::MUTED2, Theme::BORDER);
        $header = '  '.Theme::style('Repository settings', Theme::BLUE, bold: true).'  '.$badge;

        $keyWidth = 14;
        $renderKV = static function (string $key, string $val, bool $isSpecial = false) use ($keyWidth, $innerWidth): string {
            $keyPadded = Theme::style(Screen::fit($key, $keyWidth), Theme::MUTED);
            if ($isSpecial) {
                return sprintf('  %s %s', $keyPadded, $val);
            }

            $avail = $innerWidth - $keyWidth - 1;
            if ($avail > 6 && str_contains($val, "\n")) {
                $lines = explode("\n", $val);
                foreach ($lines as $i => $l) {
                    if (Screen::width($l) > $avail) {
                        $lines[$i] = Screen::truncate($l, $avail);
                    }
                }
                $indent = str_repeat(' ', $keyWidth + 1);

                return sprintf('  %s %s', $keyPadded, Theme::style(implode("\n  ".$indent, $lines), Theme::FG));
            }

            if ($avail > 6 && Screen::width($val) > $avail) {
                $val = Screen::truncate($val, $avail);
            }

            return sprintf('  %s %s', $keyPadded, Theme::style($val, Theme::FG));
        };

        $rawProjectName = $db?->projectName() ?? '';
        $projectName = '' !== $rawProjectName
            ? $rawProjectName
            : Theme::style('(none)', Theme::MUTED, italic: true);
        $rawDescription = $db?->description() ?? '';
        $description = '' !== $rawDescription
            ? $rawDescription
            : Theme::style('(none)', Theme::MUTED, italic: true);
        $type = $db?->isMirror() ?? false ? 'Mirror' : (null !== $db?->forkOf() ? 'Fork' : 'Standard');

        $accessBadge = $db?->isPrivate() ?? false
            ? Theme::style('● Private', '#ff9e64')
            : Theme::style('○ Public', Theme::GREEN);
        $visibilityBadge = $db?->isHidden() ?? false
            ? Theme::style('● Hidden', Theme::MUTED)
            : Theme::style('○ Visible', Theme::GREEN);
        $mirrorBadge = $db?->isMirror() ?? false
            ? Theme::style('● Mirror', Theme::CYAN)
            : Theme::style($type, Theme::MUTED);

        $lines = [
            '',
            $header,
            '',
            '  '.Theme::style('General Information', Theme::BLUE, bold: true),
            $renderKV('Name', $this->ctx->name, false),
            $renderKV('Project name', $projectName, !str_contains($projectName, '(none)')),
            $renderKV('Description', $description, !str_contains($description, '(none)')),
            '',
            '  '.Theme::style('Visibility & Type', Theme::BLUE, bold: true),
            $renderKV('Access', $accessBadge, true),
            $renderKV('Listing', $visibilityBadge, true),
            $renderKV('Type', $mirrorBadge, true),
            '',
            '  '.Theme::style($admin ? 'press e to edit settings' : 'read-only (write access required)', Theme::MUTED, italic: true),
        ];

        return Screen::box('', $lines, $cardWidth);
    }
}

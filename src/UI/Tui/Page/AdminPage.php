<?php

declare(strict_types=1);

namespace App\UI\Tui\Page;

use App\Access\Application\ServerPolicy;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\UI\Tui\Input\Keys;
use App\UI\Tui\Render\Screen;
use App\UI\Tui\Render\Theme;

/**
 * Server administration page (admins only): an Overview tab exposing the live
 * {@see ServerPolicy} (anonymous access level, keyless access) with keyboard
 * toggles that persist, and a Users tab listing the real {@see User} accounts.
 *
 * Mirrors Go `pkg/ui/pages/selection` admin affordances + `soft settings`.
 */
final class AdminPage extends AbstractPage
{
    private int $sel = 0;
    private ?string $flash = null;

    /** @var list<User> */
    private array $users = [];

    public function __construct(
        private readonly ServerPolicy $serverPolicy,
        private readonly UserStore $userStore,
        private readonly ServerSettings $settings,
        private readonly ?User $actor,
    ) {
    }

    private function isAdmin(): bool
    {
        return true === $this->actor?->isAdmin();
    }

    protected function load(): void
    {
        $this->users = $this->isAdmin() ? $this->userStore->all() : [];
    }

    public function handleKey(string $seq): bool
    {
        $this->flash = null;
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if (Keys::CTRL_C === $tok) {
            $this->host->quit();

            return true;
        }
        if (Keys::ESCAPE === $tok || 'q' === $txt) {
            $this->host->navigateToSelection();

            return true;
        }

        if (!$this->isAdmin()) {
            return false;
        }

        if (Keys::UP === $tok) {
            $this->sel = max(0, $this->sel - 1);

            return true;
        }
        if (Keys::DOWN === $tok) {
            $this->sel = min(1, $this->sel + 1);

            return true;
        }
        if ('a' === $txt || (0 === $this->sel && (Keys::LEFT === $tok || Keys::RIGHT === $tok))) {
            $this->cycleAnonAccess(Keys::LEFT === $tok ? -1 : 1);

            return true;
        }
        if ('k' === $txt || (1 === $this->sel && Keys::SPACE === $tok)) {
            $this->toggleKeyless();

            return true;
        }

        return false;
    }

    private function cycleAnonAccess(int $dir): void
    {
        $next = AccessLevel::from((($this->serverPolicy->anonAccess()->value + $dir) % 4 + 4) % 4);
        $this->serverPolicy->setAnonAccess($next);
        $this->flash = 'Anonymous access: '.$next->toString();
    }

    private function toggleKeyless(): void
    {
        $value = !$this->serverPolicy->allowKeyless();
        $this->serverPolicy->setAllowKeyless($value);
        $this->flash = 'Keyless access: '.($value ? 'enabled' : 'disabled');
    }

    protected function headerLines(int $cols): array
    {
        return [' '.Screen::serverBadge($this->settings->name ?: 'Soft Serve'), ''];
    }

    protected function tabBarLines(int $cols): array
    {
        return [' '.Screen::serverBadge('Server administration'), ''];
    }

    protected function footerLines(int $cols): array
    {
        if (null !== $this->flash) {
            return [Screen::fit(Theme::style(' '.$this->flash, Theme::MUTED, italic: true), $cols)];
        }
        if (!$this->isAdmin()) {
            return [Screen::fit(Theme::style('esc back', Theme::MUTED, italic: true), $cols)];
        }

        return [Screen::fit(Theme::style('↑/↓ field • ←/→ change access • space toggle • esc back', Theme::MUTED, italic: true), $cols)];
    }

    protected function paintBody(int $cols, int $rows): array
    {
        if (!$this->isAdmin()) {
            return $this->messageBody('Access denied: administrator privileges required.', $cols);
        }

        $out = [
            $this->fieldLine('Anonymous access', $this->serverPolicy->anonAccess()->toString(), 0 === $this->sel, $cols),
            $this->fieldLine('Allow keyless', $this->serverPolicy->allowKeyless() ? '[x]' : '[ ]', 1 === $this->sel, $cols),
            Screen::blank($cols),
            Screen::fit(Theme::style('Users ('.\count($this->users).'):', Theme::MUTED), $cols),
        ];
        foreach ($this->users as $user) {
            $out[] = Screen::fit('  '.Theme::style($user->username(), Theme::FG), $cols);
        }

        return $out;
    }

    private function fieldLine(string $label, string $value, bool $selected, int $cols): string
    {
        if ($selected) {
            $line = Theme::style('› '.$label, Theme::BLUE, bold: true).'  '.Theme::style($value, Theme::BLUE, bold: true);
        } else {
            $line = '  '.Theme::style($label, Theme::MUTED).'  '.Theme::style($value, Theme::FG);
        }

        return Screen::fit($line, $cols);
    }
}

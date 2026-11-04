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

/**
 * Server administration page (admins only): an Overview tab exposing the live
 * {@see ServerPolicy} (anonymous access level, keyless access) with keyboard
 * toggles that persist, and a Users tab listing the real {@see User} accounts.
 *
 * Mirrors Go `pkg/ui/pages/selection` admin affordances + `soft settings`.
 */
final class AdminPage extends AbstractPage
{
    private const TAB_OVERVIEW = 0;
    private const TAB_USERS = 1;

    /** @var list<string> */
    private const TABS = ['Overview', 'Users'];

    private int $tab = self::TAB_OVERVIEW;
    private int $sel = 0;
    private ?string $flash = null;

    /** @var list<User> */
    private array $users = [];

    public function __construct(
        private readonly ServerPolicy $serverPolicy,
        private readonly UserStore $userStore,
        private readonly ServerSettings $settings,
        private readonly ?User $actor,
    ) {}

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
        if (Keys::TAB === $tok) {
            $this->tab = ($this->tab + 1) % \count(self::TABS);
            $this->sel = 0;

            return true;
        }
        if (Keys::BACKTAB === $tok) {
            $this->tab = ($this->tab + \count(self::TABS) - 1) % \count(self::TABS);
            $this->sel = 0;

            return true;
        }

        if (!$this->isAdmin()) {
            return false;
        }

        if (self::TAB_OVERVIEW === $this->tab) {
            if ('a' === $txt) {
                $this->cycleAnonAccess();

                return true;
            }
            if ('k' === $txt) {
                $this->toggleKeyless();

                return true;
            }

            return false;
        }

        // Users tab
        $count = \count($this->users);
        if (Keys::UP === $tok || 'k' === $txt) {
            $this->sel = max(0, $this->sel - 1);

            return true;
        }
        if (Keys::DOWN === $tok || 'j' === $txt) {
            $this->sel = max(0, min($this->sel + 1, max(0, $count - 1)));

            return true;
        }

        return false;
    }

    private function cycleAnonAccess(): void
    {
        $next = AccessLevel::from(($this->serverPolicy->anonAccess()->value + 1) % 4);
        $this->serverPolicy->setAnonAccess($next);
        $this->flash = 'Anonymous access: ' . $next->toString();
    }

    private function toggleKeyless(): void
    {
        $value = !$this->serverPolicy->allowKeyless();
        $this->serverPolicy->setAllowKeyless($value);
        $this->flash = 'Keyless access: ' . ($value ? 'enabled' : 'disabled');
    }

    protected function headerText(): string
    {
        return ' Admin — ' . ($this->settings->name ?: 'Soft Serve') . ' ';
    }

    protected function tabBarText(): string
    {
        return Screen::tabBar(self::TABS, $this->tab, 120);
    }

    protected function footerText(): string
    {
        if (null !== $this->flash) {
            return ' ' . $this->flash;
        }
        if (!$this->isAdmin()) {
            return ' Esc: back';
        }

        return self::TAB_OVERVIEW === $this->tab
            ? ' a: cycle anon access · k: toggle keyless · Tab: switch · q: back'
            : ' ↑/↓ move · Tab: switch · q: back';
    }

    protected function paintBody(int $cols, int $rows): array
    {
        if (!$this->isAdmin()) {
            return $this->messageBody('Access denied: administrator privileges required.', $cols);
        }

        return self::TAB_OVERVIEW === $this->tab
            ? $this->overviewBody($cols)
            : $this->usersBody($cols, $rows);
    }

    /**
     * @return list<string>
     */
    private function overviewBody(int $cols): array
    {
        $lines = [
            '',
            '  Server         ' . ($this->settings->name ?: 'Soft Serve'),
            '  Anon access    ' . $this->serverPolicy->anonAccess()->toString(),
            '  Keyless access ' . ($this->serverPolicy->allowKeyless() ? 'enabled' : 'disabled'),
            '  Users          ' . $this->userStore->count(),
            '',
            '  a  cycle anonymous access level (no-access → read-only → read-write → admin-access)',
            '  k  toggle keyless access',
        ];

        return array_map(static fn(string $l): string => Screen::fit($l, $cols), $lines);
    }

    /**
     * @return list<string>
     */
    private function usersBody(int $cols, int $rows): array
    {
        if ([] === $this->users) {
            return $this->messageBody('No users.', $cols);
        }

        $items = [];
        foreach ($this->users as $user) {
            $meta = ($user->isAdmin() ? 'admin' : 'user') . ' · ' . \count($user->publicKeys()) . ' key(s)';
            $items[] = ['label' => $user->username(), 'meta' => $meta];
        }

        return Screen::listView($items, $this->sel, $rows, $cols);
    }
}

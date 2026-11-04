<?php

declare(strict_types=1);

namespace App\Tui\Page;

use App\Tui\Component\ComponentInterface;
use App\Tui\Component\Field\TextInputField;
use App\Tui\Component\Field\ToggleField;
use App\Tui\Component\FormComponent;
use App\Tui\Component\HeaderComponent;
use App\Tui\Component\SelectorComponent;
use App\Tui\Component\StatusbarComponent;
use App\Tui\Component\TabsComponent;
use App\Tui\Component\ViewportComponent;
use App\Tui\Event\InputEvent;
use App\Tui\Event\KeyMsg;
use App\Tui\Style\Style;
use App\Tui\Style\TokyoNight;

final class AdminPage implements ComponentInterface
{
    public const TAB_SETTINGS = 0;
    public const TAB_USERS = 1;

    private TabsComponent $tabs;
    private HeaderComponent $header;
    private StatusbarComponent $statusbar;

    private FormComponent $serverSettingsForm;
    private SelectorComponent $usersSelector;

    /** @var callable|null */
    private $onBackCallback = null;

    public function __construct()
    {
        $this->header = new HeaderComponent('Server Administration', ['Admin Access']);
        $this->tabs = new TabsComponent(['Server Settings', 'Users & Keys']);
        $this->statusbar = new StatusbarComponent();

        $keylessField = new ToggleField('allow_keyless', 'Allow keyless access (anonymous SSH)', true);
        $anonAccessField = new TextInputField('anon_access', 'Anonymous access level', 'ReadOnlyAccess');
        $this->serverSettingsForm = new FormComponent('Server Configuration', 'Manage global permissions and policies', [
            $keylessField,
            $anonAccessField,
        ]);

        $currentUser = get_current_user();
        $this->usersSelector = new SelectorComponent([
            ['id' => '1', 'label' => '👑 ' . Style::create()->bold(true)->foreground(TokyoNight::ACTIVE_BORDER)->render($currentUser) . ' (admin)'],
            ['id' => '2', 'label' => '👤 anonymous (read-only)'],
        ], 'Users');
    }

    public function setOnBack(?callable $callback): void
    {
        $this->onBackCallback = $callback;
    }

    public function init(): void
    {
        $this->tabs->setActiveTab(self::TAB_SETTINGS);
        $this->statusbar->setStatus('Admin', 'System', 'Settings', '');
    }

    public function update(InputEvent $event): ?string
    {
        if ($event instanceof KeyMsg) {
            if ($event->is('q')) {
                return 'quit';
            }
            if ($event->is(KeyMsg::ESC)) {
                if ($this->onBackCallback !== null) {
                    ($this->onBackCallback)();
                    return null;
                }
            }
        }

        // Support left/right / h/l to cycle options in Server Settings
        if ($this->tabs->getActiveTab() === self::TAB_SETTINGS && $event instanceof KeyMsg) {
            if ($event->is('h') || $event->is('l') || $event->is(KeyMsg::LEFT) || $event->is(KeyMsg::RIGHT)) {
                $this->serverSettingsForm->update($event);
                return null;
            }
        }

        $oldTab = $this->tabs->getActiveTab();
        $this->tabs->update($event);
        if ($this->tabs->getActiveTab() !== $oldTab) {
            return null;
        }

        if ($this->tabs->getActiveTab() === self::TAB_SETTINGS) {
            $this->serverSettingsForm->update($event);
        } else {
            $this->usersSelector->update($event);
        }

        return null;
    }

    public function view(int $width, int $height): string
    {
        if ($width <= 0 || $height <= 0) {
            return '';
        }

        $headerView = $this->header->view($width, 1);
        $tabsView = $this->tabs->view($width, 1);
        $statusbarView = $this->statusbar->view($width, 1);

        $bodyHeight = max(1, $height - 3);

        $bodyView = match ($this->tabs->getActiveTab()) {
            self::TAB_SETTINGS => $this->serverSettingsForm->view($width, $bodyHeight),
            self::TAB_USERS => $this->usersSelector->view($width, $bodyHeight),
            default => '',
        };

        return implode("\n", [$headerView, $tabsView, $bodyView, $statusbarView]);
    }
}

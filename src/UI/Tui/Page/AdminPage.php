<?php

declare(strict_types=1);

namespace App\UI\Tui\Page;

use CodesSh\Tui\Component\ComponentInterface;
use CodesSh\Tui\Component\Field\TextInputField;
use CodesSh\Tui\Component\Field\ToggleField;
use CodesSh\Tui\Component\FormComponent;
use CodesSh\Tui\Component\HeaderComponent;
use CodesSh\Tui\Component\SelectorComponent;
use CodesSh\Tui\Component\StatusbarComponent;
use CodesSh\Tui\Component\TabsComponent;
use CodesSh\Tui\Event\InputEvent;
use CodesSh\Tui\Event\KeyMsg;
use CodesSh\Tui\Style\Style;
use CodesSh\Tui\Style\TokyoNight;

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
    private $onBackCallback;

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
            ['id' => '1', 'label' => '👑 '.Style::create()->bold(true)->foreground(TokyoNight::ACTIVE_BORDER)->render($currentUser).' (admin)'],
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
                if (null !== $this->onBackCallback) {
                    ($this->onBackCallback)();

                    return null;
                }
            }
        }

        // Support left/right / h/l to cycle options in Server Settings
        if (self::TAB_SETTINGS === $this->tabs->getActiveTab() && $event instanceof KeyMsg) {
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

        if (self::TAB_SETTINGS === $this->tabs->getActiveTab()) {
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

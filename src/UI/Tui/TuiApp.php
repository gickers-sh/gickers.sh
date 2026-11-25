<?php

declare(strict_types=1);

namespace App\UI\Tui;

use App\UI\Tui\View\View;
use Psr\Log\LoggerInterface;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Tui;

/**
 * Per-session TUI runtime: the {@see Tui} loop, the view {@see Navigator}, the
 * {@see AsyncLoader} and the single {@see InputRouter}.
 */
final class TuiApp
{
    private const ENTER_SEQUENCE = "\x1b[?1049h\x1b[?1000h\x1b[?1002h\x1b[?1006h";
    private const RESTORE_SEQUENCE = "\x1b[?1006l\x1b[?1002l\x1b[?1000l\x1b[?1049l";

    private readonly Tui $tui;
    private readonly Navigator $navigator;
    private readonly AsyncLoader $async;
    private readonly Views $views;
    private readonly Renderer $renderer;
    private readonly HitTester $hitTester;
    private bool $restored = false;

    /**
     * @param \Closure(TuiApp): Views $viewsFactory
     */
    public function __construct(
        TerminalInterface $terminal,
        private readonly Session $session,
        \Closure $viewsFactory,
        ?LoggerInterface $logger = null,
    ) {
        $this->renderer = new Renderer(CodesStyleSheet::create());
        $keybindings = new Keybindings([
            'focus_next' => [Key::TAB, Key::F6],
            'focus_previous' => ['shift+tab', 'shift+f6'],
        ]);
        $this->tui = new Tui(terminal: $terminal, keybindings: $keybindings, renderer: $this->renderer);
        $this->async = new AsyncLoader($this->tui, $logger);
        $this->navigator = new Navigator($this->tui, $this->async);
        $this->tui->onTick(fn (): bool => $this->async->poll());
        $this->views = $viewsFactory($this);
        $this->hitTester = new HitTester($this->renderer, $this->navigator, $this->tui);
        new InputRouter($this, $this->hitTester, $keybindings->getParser());
    }

    public function tui(): Tui
    {
        return $this->tui;
    }

    public function renderer(): Renderer
    {
        return $this->renderer;
    }

    public function hitTester(): HitTester
    {
        return $this->hitTester;
    }

    public function views(): Views
    {
        return $this->views;
    }

    public function navigator(): Navigator
    {
        return $this->navigator;
    }

    public function async(): AsyncLoader
    {
        return $this->async;
    }

    public function session(): Session
    {
        return $this->session;
    }

    /**
     * Enter the alternate screen with SGR mouse reporting and mount $first.
     */
    public function start(View $first): void
    {
        $this->restored = false;
        // Alternate screen buffer (1049), button + drag mouse tracking (1000/1002),
        // SGR encoding (1006): wheel events reach the app instead of scrolling history.
        $this->tui->getTerminal()->write(self::ENTER_SEQUENCE);
        $this->navigator->reset($first);
    }

    public function run(View $first): void
    {
        $this->start($first);
        try {
            $this->tui->run();
        } finally {
            $this->restore();
        }
    }

    public function quit(): void
    {
        $this->async->cancelAll();
        $this->restore();
        $this->tui->stop();
    }

    private function restore(): void
    {
        if ($this->restored) {
            return;
        }
        $this->restored = true;
        $this->tui->getTerminal()->write(self::RESTORE_SEQUENCE);
    }
}

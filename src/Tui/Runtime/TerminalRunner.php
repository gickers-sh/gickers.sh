<?php

declare(strict_types=1);

namespace App\Tui\Runtime;

use App\Tui\Component\ComponentInterface;
use App\Tui\Event\KeyMsg;
use App\Tui\Style\ZoneManager;
use Throwable;

final class TerminalRunner
{
    private readonly Window $window;
    private readonly InputParser $parser;
    private readonly ScreenRenderer $renderer;

    public function __construct()
    {
        $this->window = new Window();
        $this->parser = new InputParser();
        $this->renderer = new ScreenRenderer();
    }

    public function run(ComponentInterface $component): void
    {
        try {
            TerminalState::enableRawMode();
            TerminalState::enableAlternateScreen();
            TerminalState::enableMouseTracking();
            TerminalState::queryBackgroundColor();

            if (defined('STDIN') && is_resource(STDIN)) {
                @stream_set_blocking(STDIN, false);
            }

            $size = $this->window->detectSize();
            $component->init();
            $component->update($size);

            $rawFrame = $component->view($size->width, $size->height);
            $frame = ZoneManager::scan($rawFrame);
            $this->renderer->render($frame, $size->width, $size->height);

            $running = true;
            $needsRender = false;

            while ($running) {
                // Check window resize
                $resizeMsg = $this->window->pollResize();
                if ($resizeMsg !== null) {
                    $size = $resizeMsg;
                    $component->update($size);
                    $needsRender = true;
                }

                // Check STDIN input
                $read = [STDIN];
                $write = null;
                $except = null;

                $numChanged = @stream_select($read, $write, $except, 0, 25000); // 25ms timeout

                if ($numChanged === false) {
                    // Signal interrupted system call, continue
                    continue;
                }

                if ($numChanged > 0) {
                    $bytes = @fread(STDIN, 4096);
                    if ($bytes !== false && $bytes !== '') {
                        $events = $this->parser->parse($bytes);
                        foreach ($events as $event) {
                            if ($event instanceof KeyMsg && $event->is('ctrl+c')) {
                                $running = false;
                                break 2;
                            }

                            $action = $component->update($event);
                            if ($action === 'quit') {
                                $running = false;
                                break 2;
                            }
                            $needsRender = true;
                        }
                    }
                }

                if ($needsRender) {
                    $rawFrame = $component->view($size->width, $size->height);
                    $frame = ZoneManager::scan($rawFrame);
                    $this->renderer->render($frame, $size->width, $size->height);
                    $needsRender = false;
                }
            }
        } catch (Throwable $e) {
            TerminalState::restore();
            throw $e;
        } finally {
            TerminalState::restore();
        }
    }
}

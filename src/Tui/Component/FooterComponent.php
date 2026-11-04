<?php

declare(strict_types=1);

namespace App\Tui\Component;

use App\Tui\Event\InputEvent;
use App\Tui\Style\Ansi;
use App\Tui\Style\TokyoNight;

final class FooterComponent implements ComponentInterface
{
    /** @var array<string, string> key => description */
    private array $hints = [];
    private string $message = '';
    private string $messageType = 'info'; // 'info', 'error', 'success'

    /**
     * @param array<string, string> $hints Map of keybinding => action (e.g. ['q' => 'quit', '?' => 'help'])
     */
    public function __construct(array $hints = [])
    {
        $this->hints = $hints;
    }

    public function init(): void
    {
        $this->message = '';
    }

    /**
     * @param array<string, string> $hints
     */
    public function setHints(array $hints): void
    {
        $this->hints = $hints;
    }

    public function setMessage(string $message, string $type = 'info'): void
    {
        $this->message = $message;
        $this->messageType = $type;
    }

    public function clearMessage(): void
    {
        $this->message = '';
    }

    public function update(InputEvent $event): ?string
    {
        return null;
    }

    public function view(int $width, int $height): string
    {
        $reset = TokyoNight::reset();
        $bg = TokyoNight::hexToAnsi(TokyoNight::BG, true);

        // If there is an active message / alert, display it prominently
        if ($this->message !== '') {
            $msgColor = match ($this->messageType) {
                'error' => TokyoNight::hexToAnsi(TokyoNight::ERROR),
                'success' => TokyoNight::hexToAnsi(TokyoNight::SUCCESS),
                default => TokyoNight::hexToAnsi(TokyoNight::WARNING),
            };
            $content = ' ' . $msgColor . "\x1b[1m" . $this->message . $reset;
            $w = Ansi::width($content);
            $pad = max(0, $width - $w);
            return $content . str_repeat(' ', $pad);
        }
        $keyFg = TokyoNight::hexToAnsi(TokyoNight::HELP_KEY);
        $descFg = TokyoNight::hexToAnsi(TokyoNight::HELP_VALUE);
        $divFg = TokyoNight::hexToAnsi(TokyoNight::HELP_DIVIDER);
        $divider = $divFg . ' · ' . $reset;

        $hintParts = [];
        foreach ($this->hints as $key => $desc) {
            $keyStr = $keyFg . $key . $reset;
            $descStr = $descFg . $desc . $reset;
            $hintParts[] = $keyStr . ' ' . $descStr;
        }

        $line = implode($divider, $hintParts);
        if ($width > 0 && Ansi::width($line) > $width) {
            $line = Ansi::truncate($line, $width);
        }

        return $line;
    }
}

<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command;

use App\Identity\Domain\Model\User;
use App\Shared\Domain\Exception\AccessDenied;
use App\UI\Ssh\SshSession;
use App\UI\Tui\Render\Theme;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractSshCommand extends Command
{
    abstract public static function sshName(): string;

    /**
     * @return list<string>
     */
    public static function sshAliases(): array
    {
        return [];
    }

    public function __construct(
        protected readonly SshSession $sshSession,
    ) {
        parent::__construct(static::sshName());
        $this->setAliases(static::sshAliases());
    }

    protected function actor(): ?User
    {
        return $this->sshSession->user();
    }

    protected function line(OutputInterface $output, string $message = ''): void
    {
        $output->writeln($message, OutputInterface::OUTPUT_RAW);
    }

    protected function errLine(OutputInterface $output, string $message = ''): void
    {
        $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $err->writeln($message, OutputInterface::OUTPUT_RAW);
    }

    protected function writeRaw(OutputInterface $output, string $content): void
    {
        $output->write($content, false, OutputInterface::OUTPUT_RAW);
    }

    protected function isDecorated(OutputInterface $output): bool
    {
        return $output->isDecorated();
    }

    protected function style(
        OutputInterface $output,
        string $text,
        ?string $fg = null,
        ?string $bg = null,
        bool $bold = false,
        bool $italic = false,
        bool $underline = false,
        bool $faint = false,
    ): string {
        if (!$output->isDecorated()) {
            return $text;
        }

        return Theme::style($text, $fg, $bg, $bold, $italic, $underline, $faint);
    }

    protected function formatKeyValue(
        OutputInterface $output,
        string $key,
        string|int|float|bool|null $value,
        ?string $valColor = null,
        bool $valBold = false,
    ): string {
        if (!$output->isDecorated()) {
            $valStr = is_bool($value) ? ($value ? 'true' : 'false') : (string) ($value ?? '');

            return sprintf('%s: %s', $key, $valStr);
        }

        $keyStyled = Theme::style($key . ':', Theme::BLUE, bold: true);
        if (is_bool($value)) {
            $valStyled = Theme::style($value ? 'true' : 'false', $value ? Theme::GREEN : Theme::MUTED, bold: true);
        } elseif (null === $value || '' === $value) {
            $valStyled = Theme::style('-', Theme::MUTED);
        } else {
            $color = $valColor ?? (is_numeric($value) ? Theme::ORANGE : Theme::FG);
            $valStyled = Theme::style((string) $value, $color, bold: $valBold);
        }

        return sprintf('%s %s', $keyStyled, $valStyled);
    }

    protected function formatBoolean(OutputInterface $output, bool $value): string
    {
        if (!$output->isDecorated()) {
            return $value ? 'true' : 'false';
        }

        return Theme::style($value ? 'true' : 'false', $value ? Theme::GREEN : Theme::MUTED, bold: true);
    }

    protected function formatHeader(OutputInterface $output, string $header): string
    {
        if (!$output->isDecorated()) {
            return $header;
        }

        return Theme::style($header, Theme::BLUE, bold: true);
    }

    protected function formatMuted(OutputInterface $output, string $text): string
    {
        if (!$output->isDecorated()) {
            return $text;
        }

        return Theme::style($text, Theme::MUTED);
    }

    protected function formatPublicKey(OutputInterface $output, string $authorizedKey): string
    {
        if (!$output->isDecorated()) {
            return $authorizedKey;
        }

        $parts = preg_split('/\s+/', trim($authorizedKey), 3);
        $algo = $parts[0] ?? '';
        $key = $parts[1] ?? '';
        $comment = $parts[2] ?? '';

        $styled = Theme::style($algo, Theme::TEAL, bold: true);
        if ('' !== $key) {
            $styled .= ' ' . Theme::style($key, Theme::FG_DIM);
        }
        if ('' !== $comment) {
            $styled .= ' ' . Theme::style($comment, Theme::MUTED, italic: true);
        }

        return $styled;
    }

    protected function stringArgument(InputInterface $input, string $name, string $default = ''): string
    {
        $val = $input->getArgument($name);

        return is_string($val) ? $val : $default;
    }

    protected function nullableStringArgument(InputInterface $input, string $name): ?string
    {
        $val = $input->getArgument($name);

        return is_string($val) && '' !== trim($val) ? trim($val) : null;
    }

    protected function stringOption(InputInterface $input, string $name, string $default = ''): string
    {
        $val = $input->getOption($name);

        return is_string($val) ? $val : $default;
    }

    protected function nullableStringOption(InputInterface $input, string $name): ?string
    {
        $val = $input->getOption($name);

        return is_string($val) && '' !== trim($val) ? trim($val) : null;
    }

    protected function resolveTerminalWidth(InputInterface $input, OutputInterface $output, int $defaultFallback = 100): int
    {
        if ($input->hasOption('width')) {
            $rawWidth = $input->getOption('width');
            $width = is_numeric($rawWidth) ? (int) $rawWidth : 0;
            if ($width > 0) {
                return $width;
            }
        }

        // 1. Explicit COLUMNS environment variable (set by codes-ssh when PTY is allocated or by client via SendEnv)
        $envColsRaw = getenv('COLUMNS') ?: ($_SERVER['COLUMNS'] ?? ($_ENV['COLUMNS'] ?? null));
        $envCols = is_numeric($envColsRaw) ? (int) $envColsRaw : 0;
        if ($envCols > 0) {
            return $envCols;
        }

        // 2. Symfony Terminal auto-detection (stty size or system mode)
        $termCols = (new \Symfony\Component\Console\Terminal())->getWidth();
        if ($termCols > 0 && 80 !== $termCols) {
            return $termCols;
        }

        // 3. Non-blocking cursor position report (CPR \e[6n) for interactive terminals without PTY
        if ($input->isInteractive() && !getenv('SSH_PIPED') && '1' !== ($_SERVER['SSH_PIPED'] ?? '')) {
            $stream = $input instanceof \Symfony\Component\Console\Input\StreamableInputInterface ? $input->getStream() : null;
            $stream ??= \defined('STDIN') ? \STDIN : null;

            if (is_resource($stream) && !feof($stream)) {
                $isTestEnv = 'test' === ($_SERVER['APP_ENV'] ?? '') || 'test' === ($_ENV['APP_ENV'] ?? '');
                $output->write("\e7\e[9999C\e[6n\e8", false, OutputInterface::OUTPUT_RAW);

                if ($isTestEnv) {
                    $line = fgets($stream, 512);
                    if (is_string($line) && preg_match('/\x1b\[(\d+);(\d+)R/', $line, $matches)) {
                        $detected = (int) $matches[2];
                        if ($detected > 0) {
                            return $detected;
                        }
                    }
                } else {
                    $r = [$stream];
                    $w = [];
                    $e = [];
                    // Wait up to 50ms (50,000µs) for terminal emulator response
                    if (@stream_select($r, $w, $e, 0, 50000) > 0) {
                        $buf = '';
                        while (true) {
                            $ch = fgetc($stream);
                            if (false === $ch || '' === $ch) {
                                break;
                            }
                            $buf .= $ch;
                            if ('R' === $ch || strlen($buf) > 32) {
                                break;
                            }
                        }
                        if (preg_match('/\x1b\[(\d+);(\d+)R/', $buf, $matches)) {
                            $detected = (int) $matches[2];
                            if ($detected > 0) {
                                return $detected;
                            }
                        }
                    }
                }
            }
        }

        return $defaultFallback > 0 ? $defaultFallback : ($termCols > 0 ? $termCols : 80);
    }

    protected function promptPassword(InputInterface $input, OutputInterface $output, string $prompt): string
    {
        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
        $stream ??= \defined('STDIN') ? \STDIN : null;

        $isTestEnv = 'test' === ($_SERVER['APP_ENV'] ?? '') || 'test' === ($_ENV['APP_ENV'] ?? '');
        if ($isTestEnv) {
            if (null !== $stream && is_resource($stream) && !feof($stream)) {
                $line = fgets($stream, 4096);

                return false !== $line ? trim($line, "\r\n") : '';
            }

            return '';
        }

        $output->write($prompt, false, OutputInterface::OUTPUT_RAW);

        $hasStty = false;
        if (\function_exists('shell_exec') && !getenv('SSH_PIPED')) {
            $sttyMode = @shell_exec('stty -g 2>/dev/null');
            if (false !== $sttyMode && null !== $sttyMode && '' !== trim($sttyMode)) {
                @shell_exec('stty -echo 2>/dev/null');
                $hasStty = true;
            }
        }

        try {
            $line = '';
            if (null !== $stream && is_resource($stream) && !feof($stream)) {
                $line = (string) fgets($stream, 4096);
            }

            return trim($line, "\r\n");
        } finally {
            if ($hasStty) {
                @shell_exec('stty echo 2>/dev/null');
                $output->writeln('', OutputInterface::OUTPUT_RAW);
            }
        }
    }

    protected function clientIdentifier(): string
    {
        $sshClient = getenv('SSH_CLIENT') ?: ($_SERVER['SSH_CLIENT'] ?? null);
        if (is_string($sshClient) && '' !== trim($sshClient)) {
            $parts = explode(' ', trim($sshClient));
            if ('' !== $parts[0]) {
                return $parts[0];
            }
        }

        $fp = $this->sshSession->publicKeyFingerprint();
        if (null !== $fp && '' !== trim($fp)) {
            return $fp;
        }

        return '127.0.0.1';
    }

    /**
     * Probes whether the current SSH connection is attached to an interactive terminal
     * or being piped into a local process (e.g. `ssh codes.sh signup | bash`).
     */
    protected function probeInteractiveTerminal(InputInterface $input, OutputInterface $output, int $timeoutMicros = 50000): bool
    {
        // 1. If explicit PTY was allocated, it is an interactive terminal session
        $sshTty = getenv('SSH_TTY') ?: ($_SERVER['SSH_TTY'] ?? null);
        if (is_string($sshTty) && '' !== trim($sshTty)) {
            return true;
        }

        // 2. In test environment, respect SSH_PIPED override
        $isTestEnv = 'test' === ($_SERVER['APP_ENV'] ?? '') || 'test' === ($_ENV['APP_ENV'] ?? '');
        if ($isTestEnv) {
            return '1' !== (getenv('SSH_PIPED') ?: ($_SERVER['SSH_PIPED'] ?? ''));
        }

        // 3. Check STDIN resource availability
        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
        $stream ??= \defined('STDIN') ? \STDIN : null;

        if (!is_resource($stream) || feof($stream)) {
            return false;
        }

        // 4. Send probe: comment line containing VT100 CPR request (\033[6n)
        // - In bash/PowerShell, '# ...' is a comment and ignored without syntax error.
        // - In an interactive terminal, the terminal emulator intercepts \033[6n and sends \033[<row>;<col>R back on stdin.
        $output->write("# \033[6n\n", false, OutputInterface::OUTPUT_RAW);

        $r = [$stream];
        $w = [];
        $e = [];

        if (@stream_select($r, $w, $e, 0, $timeoutMicros) > 0) {
            $buf = '';
            while (true) {
                $ch = fgetc($stream);
                if (false === $ch || '' === $ch) {
                    break;
                }
                $buf .= $ch;
                if ('R' === $ch || strlen($buf) > 32) {
                    break;
                }
            }

            if (preg_match('/\x1b\[\d+;\d+R/', $buf)) {
                // Clear the probe line on screen: move cursor up 1 line and clear line
                $output->write("\033[1A\033[2K\r", false, OutputInterface::OUTPUT_RAW);

                return true;
            }
        }

        return false;
    }
}

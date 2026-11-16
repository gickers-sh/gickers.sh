<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command;

use App\Repo\Application\Query\RepoQuery;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\UI\Ssh\SshSession;
use App\UI\Tui\Render\TermstrapRenderer;
use App\UI\Tui\Render\Theme;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

final class AboutCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'about';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly RepoQuery $repoQuery,
        private readonly BareRepositoryStorage $storage,
        private readonly GitRepositoryReader $gitReader,
        private readonly TermstrapRenderer $renderer,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Display server about / README rendered with Termstrap')
            ->addArgument('reference', InputArgument::OPTIONAL, 'Git reference (branch, tag, or commit SHA)', 'HEAD')
            ->addOption('width', 'w', InputOption::VALUE_REQUIRED, 'Terminal width in columns (0 = auto-detect)', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ref = $this->stringArgument($input, 'reference', 'HEAD');
        $width = $this->resolveTerminalWidth($input, $output);

        $readme = null;
        try {
            $repo = $this->repoQuery->get($this->actor(), '.soft-serve');
            $gitRepo = $this->storage->open($repo->name());
            $readme = $this->gitReader->readme($gitRepo, $ref);
        } catch (\Throwable) {
            $readme = null;
        }

        if (null !== $readme && '' !== trim($readme['content'])) {
            $rendered = $this->renderer->renderDocument($readme['path'], $readme['content'], $width);
            $this->writeRaw($output, $rendered.(str_ends_with($rendered, "\n") ? '' : "\n"));
        } else {
            $lines = [
                '',
                Theme::style('No readme found.', Theme::MUTED),
                '',
                Theme::style('Create a `.soft-serve` repository and add a README.md file to display readme.', Theme::MUTED),
                '',
            ];
            $this->writeRaw($output, implode("\n", $lines));
        }

        return Command::SUCCESS;
    }

    private function resolveTerminalWidth(InputInterface $input, OutputInterface $output): int
    {
        $width = (int) $input->getOption('width');
        if ($width > 0) {
            return $width;
        }

        // If session has an allocated PTY (e.g. ssh -t),
        // we can query the terminal columns directly without waiting for [Enter].
        if (!empty(getenv('SSH_TTY'))) {
            $cols = (new Terminal())->getWidth();
            if ($cols > 0) {
                return $cols;
            }
        }

        // Determine input stream (supports CommandTester stream or STDIN)
        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
        $isTestEnv = 'test' === ($_SERVER['APP_ENV'] ?? '') || 'test' === ($_ENV['APP_ENV'] ?? '');

        // In test environments without explicitly piped input stream, avoid blocking
        if ($isTestEnv && null === $stream) {
            $cols = (new Terminal())->getWidth();

            return $cols > 0 ? $cols : 80;
        }

        $stream ??= \defined('STDIN') ? \STDIN : null;
        if (null === $stream || !is_resource($stream) || feof($stream)) {
            $cols = (new Terminal())->getWidth();

            return $cols > 0 ? $cols : 80;
        }

        // Option B: Display prompt, turn on conceal (\e[8m) to hide echoed CPR, and query cursor position
        $prompt = Theme::style('codes.sh about', Theme::BLUE, bold: true)
            .Theme::style(' — Press [Enter] to view...', Theme::MUTED);

        // \e[8m conceals echoed characters; \e7 saves cursor; \e[9999C moves right; \e[6n queries CPR; \e8 restores cursor
        $output->write($prompt."\e[8m\e7\e[9999C\e[6n\e8", false, OutputInterface::OUTPUT_RAW);

        $line = null;
        if ($isTestEnv) {
            $line = fgets($stream, 4096);
        } else {
            // Wait up to 30s for the user to press [Enter]
            $r = [$stream];
            $w = [];
            $e = [];
            if (@stream_select($r, $w, $e, 30) > 0) {
                $line = fgets($stream, 4096);
            }
        }

        // Clear both the newline and the prompt line, reset attributes (\e[0m)
        $output->write("\r\e[2K\e[1A\r\e[2K\e[0m", false, OutputInterface::OUTPUT_RAW);

        if (is_string($line) && preg_match('/\x1b\[(\d+);(\d+)R/', $line, $matches)) {
            $detected = (int) $matches[2];
            if ($detected > 0) {
                return $detected;
            }
        }

        $cols = (new Terminal())->getWidth();

        return $cols > 0 ? $cols : 80;
    }
}

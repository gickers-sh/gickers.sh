<?php

declare(strict_types=1);

namespace App\UI\Console\Command;

use App\UI\Ssh\SshSession;
use App\UI\Tui\TuiAppFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Tui\Terminal\Terminal;
use Symfony\Component\Tui\Terminal\TerminalInterface;

#[AsCommand(
    name: 'soft:tui',
    description: 'Launch the full multi-repository gickers.sh TUI',
    aliases: ['tui'],
)]
final class TuiCommand extends Command
{
    public function __construct(
        private readonly TuiAppFactory $apps,
        private readonly ?SshSession $sshSession = null,
        private readonly ?TerminalInterface $terminal = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'initial-repo',
            InputArgument::OPTIONAL,
            'Initial Git repository to open',
            null,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $actor = $this->sshSession?->user();
        $app = $this->apps->create($this->terminal ?? new Terminal(), $actor, true);

        $initialRepo = $input->getArgument('initial-repo');
        $initialView = null;
        if (null !== $initialRepo && is_string($initialRepo) && '' !== trim($initialRepo)) {
            try {
                $initialView = $app->views()->repoByName(trim($initialRepo));
            } catch (\Throwable $e) {
                $this->logger?->warning(sprintf('TUI: Failed to open initial repository "%s": %s', $initialRepo, $e->getMessage()), [
                    'repo' => $initialRepo,
                    'actor' => $actor?->username(),
                    'exception' => $e,
                ]);
            }
        }

        $app->run($initialView ?? $app->views()->selection());

        return Command::SUCCESS;
    }
}

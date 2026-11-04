<?php

declare(strict_types=1);

namespace App\UI\Console\Command;

use App\UI\Ssh\SshSession;
use App\UI\Tui\TuiPageFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Tui\Terminal\Terminal;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Tui;

#[AsCommand(
    name: 'soft:tui',
    description: 'Launch the full multi-repository codes.sh TUI',
    aliases: ['tui'],
)]
final class TuiCommand extends Command
{
    public function __construct(
        private readonly TuiPageFactory $factory,
        private readonly ?SshSession $sshSession = null,
        private readonly ?TerminalInterface $terminal = null,
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
        $tui = new Tui(terminal: $this->terminal ?? new Terminal());
        $root = $this->factory->createRoot($tui, $actor);

        $initialRepo = $input->getArgument('initial-repo');
        $initialPage = null;
        if (null !== $initialRepo && is_string($initialRepo) && '' !== trim($initialRepo)) {
            try {
                $initialPage = $this->factory->repoPageForName($actor, trim($initialRepo));
            } catch (\Throwable) {
                // If initial repo cannot be opened (unreadable/not found), fall back to selection page
            }
        }

        $initialPage ??= $this->factory->selectionPage($actor);
        $root->start($initialPage);
        $tui->run();

        return Command::SUCCESS;
    }
}

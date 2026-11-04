<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Backend\BackendService;
use App\Service\Git\GitService;
use App\Service\Render\TermstrapRenderer;
use App\Tui\Page\RootUI;
use App\Tui\Runtime\TerminalRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'soft:tui',
    description: 'Launch the full multi-repository codes.sh TUI',
    aliases: ['tui']
)]
final class TuiCommand extends Command
{
    public function __construct(
        private readonly GitService $gitService,
        private readonly TermstrapRenderer $renderer,
        private readonly TerminalRunner $runner,
        private readonly BackendService $backend,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'initial-repo',
            InputArgument::OPTIONAL,
            'Initial Git repository to open',
            null
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $initialRepo = $input->getArgument('initial-repo');

        $root = new RootUI($this->gitService, $this->renderer, $this->backend);
        if ($initialRepo !== null && is_string($initialRepo) && trim($initialRepo) !== '') {
            try {
                $root->openRepoByPath($initialRepo);
            } catch (Throwable $e) {
                $output->writeln('<error>Warning: Could not open initial repo: ' . $e->getMessage() . '</error>');
            }
        }

        $this->runner->run($root);

        return Command::SUCCESS;
    }
}

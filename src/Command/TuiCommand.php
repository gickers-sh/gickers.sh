<?php

declare(strict_types=1);

namespace App\Command;

use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Service\Backend\BackendService;
use App\UI\Tui\Page\RootUI;
use App\UI\Tui\Render\TermstrapRenderer;
use CodesSh\Tui\Runtime\TerminalRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'soft:tui',
    description: 'Launch the full multi-repository codes.sh TUI',
    aliases: ['tui']
)]
final class TuiCommand extends Command
{
    public function __construct(
        private readonly GitRepositoryReader $gitService,
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
        if (null !== $initialRepo && is_string($initialRepo) && '' !== trim($initialRepo)) {
            try {
                $root->openRepoByPath($initialRepo);
            } catch (\Throwable $e) {
                $output->writeln('<error>Warning: Could not open initial repo: '.$e->getMessage().'</error>');
            }
        }

        $this->runner->run($root);

        return Command::SUCCESS;
    }
}

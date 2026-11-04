<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Git\GitService;
use App\Service\Render\TermstrapRenderer;
use App\Tui\Page\RepoPage;
use App\Tui\Runtime\TerminalRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'soft:browse',
    description: 'Browse a Git repository in the reactive terminal UI',
    aliases: ['browse']
)]
final class BrowseCommand extends Command
{
    public function __construct(
        private readonly GitService $gitService,
        private readonly TermstrapRenderer $renderer,
        private readonly TerminalRunner $runner
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'path',
            InputArgument::OPTIONAL,
            'Path to the local Git repository',
            '.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = (string) $input->getArgument('path');

        try {
            $repo = $this->gitService->openRepository($path);
        } catch (Throwable $e) {
            $output->writeln('<error>Failed to open git repository: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $page = new RepoPage($repo, $this->gitService, $this->renderer);
        $this->runner->run($page);

        return Command::SUCCESS;
    }
}

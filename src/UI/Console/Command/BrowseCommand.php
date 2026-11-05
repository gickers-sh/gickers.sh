<?php

declare(strict_types=1);

namespace App\UI\Console\Command;

use App\Repo\Infrastructure\Git\GitRepositoryReader;
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
    name: 'soft:browse',
    description: 'Browse a Git repository in the reactive terminal UI',
    aliases: ['browse'],
)]
final class BrowseCommand extends Command
{
    public function __construct(
        private readonly TuiPageFactory $factory,
        private readonly GitRepositoryReader $gitReader,
        private readonly ?SshSession $sshSession = null,
        private readonly ?TerminalInterface $terminal = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'path',
            InputArgument::OPTIONAL,
            'Path to the local Git repository',
            '.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawPath = $input->getArgument('path');
        $path = \is_string($rawPath) && '' !== $rawPath ? $rawPath : '.';

        try {
            $git = $this->gitReader->openRepository($path);
        } catch (\Throwable $e) {
            $output->writeln('<error>Failed to open git repository: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $actor = $this->sshSession?->user();
        $tui = new Tui(terminal: $this->terminal ?? new Terminal());
        $root = $this->factory->createBrowseRoot($tui, $actor);
        $root->start($this->factory->repoPageForGit($actor, $git));
        try {
            $tui->run();
        } finally {
            $root->quit();
        }

        return Command::SUCCESS;
    }
}

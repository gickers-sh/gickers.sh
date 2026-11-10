<?php

declare(strict_types=1);

namespace App\UI\Console\Command\Admin;

use App\Repo\Application\SyncMirrors;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Refreshes every mirror repository from its origin. The same job runs hourly from the
 * Messenger scheduler (`soft serve`); this command is the entry point for a cron or a manual
 * run. Failures are reported per mirror on stderr and never abort the remaining mirrors.
 */
#[AsCommand(
    name: 'soft:repo:sync-mirrors',
    description: 'Refresh every mirror repository from its origin',
)]
final class SyncMirrorsCommand extends Command
{
    public function __construct(private readonly SyncMirrors $syncMirrors)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $report = ($this->syncMirrors)();

        foreach ($report->synced as $name) {
            $output->writeln(sprintf('Synced <info>%s</info>', OutputFormatter::escape($name)));
        }
        foreach ($report->failures as $failure) {
            $errOutput->writeln(sprintf('<error>%s: %s</error>', OutputFormatter::escape($failure['name']), OutputFormatter::escape($failure['reason'])));
        }

        $output->writeln(0 === $report->total()
            ? 'No mirror repositories.'
            : sprintf('%d of %d mirror repositories synced, %d failed.', \count($report->synced), $report->total(), \count($report->failures)));

        return Command::SUCCESS;
    }
}

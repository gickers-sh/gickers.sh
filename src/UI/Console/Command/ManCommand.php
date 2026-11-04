<?php

declare(strict_types=1);

namespace App\UI\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generates the soft(1) roff manual page from the registered `soft:*` command definitions.
 */
#[AsCommand(name: 'soft:man', description: 'Print the soft(1) manual page')]
final class ManCommand extends Command
{
 protected function execute(InputInterface $input, OutputInterface $output): int
 {
  $application = $this->getApplication();
  \assert(null !== $application);

  $lines = [
   '.TH SOFT 1 "' . date('F Y') . '" "codes.sh" "Soft Serve Manual"',
   '.SH NAME',
   'soft \- A self-hostable Git server for the command line',
   '.SH SYNOPSIS',
   '\fBsoft\fR [\fIcommand\fR] [\fIflags\fR]',
   '.SH COMMANDS',
  ];

  foreach ($application->all('soft') as $name => $command) {
   if ($command->isHidden() || $name !== $command->getName()) {
    continue;
   }
   $lines[] = '.TP';
   $lines[] = '\fB' . str_replace(':', ' ', substr($name, 5)) . '\fR ' . $this->escape($command->getSynopsis(true));
   $lines[] = $this->escape($command->getDescription());
  }

  $output->writeln($lines, OutputInterface::OUTPUT_RAW);

  return Command::SUCCESS;
 }

 private function escape(string $text): string
 {
  return str_replace(['\\', '-'], ['\\\\', '\-'], $text);
 }
}

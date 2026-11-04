<?php

declare(strict_types=1);

namespace App\Command;

use App\Identity\Infrastructure\Ssh\AuthorizedKeysFileWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'soft:ssh:authorized-keys',
    description: 'Regenerate the OpenSSH authorized_keys file for codes.sh users',
)]
final class SshAuthorizedKeysCommand extends Command
{
    public function __construct(
        private readonly AuthorizedKeysFileWriter $writer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->writer->write();
        $output->writeln('authorized_keys regenerated successfully.');

        return Command::SUCCESS;
    }
}

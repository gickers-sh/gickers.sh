<?php

declare(strict_types=1);

namespace App\UI\Console\Command\Admin;

use App\Identity\Application\BootstrapServer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'soft:admin:bootstrap',
    description: 'Create the admin user and register SOFT_SERVE_INITIAL_ADMIN_KEYS',
)]
final class BootstrapCommand extends Command
{
    public function __construct(private readonly BootstrapServer $bootstrapServer)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ($this->bootstrapServer)();

        return Command::SUCCESS;
    }
}

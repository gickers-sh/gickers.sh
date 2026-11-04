<?php

declare(strict_types=1);

namespace App\UI\Console\Command\Hook;

use App\Repo\Infrastructure\Git\CustomHookRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'soft:hook:pre-receive',
    description: 'Execute Git pre-receive hook',
    aliases: ['git:hook:pre-receive', 'hook:pre-receive'],
)]
final class PreReceiveCommand extends Command
{
    private ?string $inputStream = null;

    public function __construct(
        private readonly CustomHookRunner $customHookRunner,
    ) {
        parent::__construct();
    }

    public function setInputStream(?string $input): void
    {
        $this->inputStream = $input;
    }

    protected function configure(): void
    {
        $this
            ->addOption('stdin', null, InputOption::VALUE_OPTIONAL, 'Explicit stdin payload');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stdin = $this->resolveStdin($input);

        $exitCode = $this->customHookRunner->run(
            hookName: 'pre-receive',
            stdin: $stdin,
            args: [],
            env: [],
            onOutput: static function (string $type, string $buffer) use ($output): void {
                $output->write($buffer);
            },
        );

        return 0 !== $exitCode ? $exitCode : Command::SUCCESS;
    }

    private function resolveStdin(InputInterface $input): string
    {
        if (null !== $this->inputStream) {
            return $this->inputStream;
        }

        $stdinOpt = $input->getOption('stdin');
        if (is_string($stdinOpt) && '' !== $stdinOpt) {
            return $stdinOpt;
        }

        $raw = @file_get_contents('php://stdin');

        return false !== $raw ? $raw : '';
    }
}

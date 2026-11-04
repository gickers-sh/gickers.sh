<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command;

use App\Identity\Domain\Model\User;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractSshCommand extends Command
{
    abstract public static function sshName(): string;

    /**
     * @return list<string>
     */
    public static function sshAliases(): array
    {
        return [];
    }

    public function __construct(
        protected readonly SshSession $sshSession,
    ) {
        parent::__construct(static::sshName());
        $this->setAliases(static::sshAliases());
    }

    protected function actor(): ?User
    {
        return $this->sshSession->user();
    }

    protected function line(OutputInterface $output, string $message = ''): void
    {
        $output->writeln($message, OutputInterface::OUTPUT_RAW);
    }

    protected function errLine(OutputInterface $output, string $message = ''): void
    {
        $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $err->writeln($message, OutputInterface::OUTPUT_RAW);
    }

    protected function writeRaw(OutputInterface $output, string $content): void
    {
        $output->write($content, false, OutputInterface::OUTPUT_RAW);
    }

    protected function stringArgument(InputInterface $input, string $name, string $default = ''): string
    {
        $val = $input->getArgument($name);

        return is_string($val) ? $val : $default;
    }

    protected function nullableStringArgument(InputInterface $input, string $name): ?string
    {
        $val = $input->getArgument($name);

        return is_string($val) && '' !== trim($val) ? trim($val) : null;
    }

    protected function stringOption(InputInterface $input, string $name, string $default = ''): string
    {
        $val = $input->getOption($name);

        return is_string($val) ? $val : $default;
    }

    protected function nullableStringOption(InputInterface $input, string $name): ?string
    {
        $val = $input->getOption($name);

        return is_string($val) && '' !== trim($val) ? trim($val) : null;
    }
}

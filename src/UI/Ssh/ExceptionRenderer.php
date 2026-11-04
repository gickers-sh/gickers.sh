<?php

declare(strict_types=1);

namespace App\UI\Ssh;

use App\Shared\Domain\Exception\DomainException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleExceptionInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class ExceptionRenderer
{
    public function __construct(
        private ?LoggerInterface $logger = null,
    ) {}

    public function render(\Throwable $exception, OutputInterface $output): int
    {
        $errOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        if ($exception instanceof DomainException || $exception instanceof ConsoleExceptionInterface) {
            $errOutput->writeln(sprintf('Error: %s', $exception->getMessage()), OutputInterface::OUTPUT_RAW);

            return 1;
        }

        $this->logger?->error(sprintf('Unhandled SSH error: %s', $exception->getMessage()), [
            'exception' => $exception,
        ]);

        $errOutput->writeln('Error: An unexpected error occurred.', OutputInterface::OUTPUT_RAW);

        return 1;
    }
}

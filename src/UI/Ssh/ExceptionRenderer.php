<?php

declare(strict_types=1);

namespace App\UI\Ssh;

use App\Shared\Domain\Exception\DomainException;
use App\UI\Tui\Render\Theme;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleExceptionInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class ExceptionRenderer
{
    public function __construct(
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function render(\Throwable $exception, OutputInterface $output): int
    {
        $errOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        if ($exception instanceof DomainException || $exception instanceof ConsoleExceptionInterface) {
            $this->writeError($errOutput, $exception->getMessage());

            return 1;
        }

        $this->logger?->error(sprintf('Unhandled SSH error: %s', $exception->getMessage()), [
            'exception' => $exception,
        ]);

        $this->writeError($errOutput, 'An internal server error occurred.');

        return 1;
    }

    private function writeError(OutputInterface $errOutput, string $message): void
    {
        if ($errOutput->isDecorated()) {
            $prefix = Theme::style('Error:', Theme::SELECTOR, bold: true);
            $errOutput->writeln(sprintf('%s %s', $prefix, Theme::style($message, Theme::FG)), OutputInterface::OUTPUT_RAW);
        } else {
            $errOutput->writeln(sprintf('Error: %s', $message), OutputInterface::OUTPUT_RAW);
        }
    }
}

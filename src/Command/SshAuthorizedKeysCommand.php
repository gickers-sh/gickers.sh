<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Ssh\SshKeyComparator;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'soft:ssh:authorized-keys',
    description: 'Generate OpenSSH AuthorizedKeysCommand lines for codes.sh users'
)]
final class SshAuthorizedKeysCommand extends Command
{
    public function __construct(
        private readonly SshKeyComparator $comparator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'username',
                InputArgument::OPTIONAL,
                'Username passed by OpenSSH %u',
                null
            )
            ->addArgument(
                'key',
                InputArgument::OPTIONAL,
                'Public key or base64 passed by OpenSSH %k',
                null
            )
            ->addArgument(
                'key-type',
                InputArgument::OPTIONAL,
                'Key type passed by OpenSSH %t',
                null
            )
            ->addOption(
                'console-bin',
                'c',
                InputOption::VALUE_OPTIONAL,
                'Path to bin/console executable',
                null
            )
            ->addOption(
                'keys-file',
                'f',
                InputOption::VALUE_OPTIONAL,
                'Path to a file containing authorized_keys lines',
                null
            )
            ->addOption(
                'key-line',
                'l',
                InputOption::VALUE_OPTIONAL,
                'A single raw authorized_key line to format',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $binConsole = $input->getOption('console-bin');
        if ($binConsole === null || !is_string($binConsole) || trim($binConsole) === '') {
            $binConsole = dirname(__DIR__, 2) . '/bin/console';
        }

        // 1. Direct single key line option
        $keyLine = $input->getOption('key-line');
        if ($keyLine !== null && is_string($keyLine) && trim($keyLine) !== '') {
            try {
                $line = $this->formatAuthorizedKeyLine(trim($keyLine), $binConsole);
                $output->writeln($line);
                return Command::SUCCESS;
            } catch (InvalidArgumentException $e) {
                $output->writeln(sprintf('# Error formatting key: %s', $e->getMessage()), OutputInterface::VERBOSITY_VERBOSE);
                return Command::FAILURE;
            }
        }

        // 2. Direct key passed as argument (OpenSSH %k %t)
        $keyArg = $input->getArgument('key');
        $keyTypeArg = $input->getArgument('key-type');
        if ($keyArg !== null && is_string($keyArg) && trim($keyArg) !== '') {
            $fullKey = ($keyTypeArg ? trim($keyTypeArg) . ' ' : '') . trim($keyArg);
            try {
                $line = $this->formatAuthorizedKeyLine($fullKey, $binConsole);
                $output->writeln($line);
                return Command::SUCCESS;
            } catch (InvalidArgumentException) {
                // If %k alone is not a full line, continue to search keys-file
            }
        }

        // 3. Keys file specified or auto-discovered
        $keysFile = $input->getOption('keys-file');
        if ($keysFile === null || !is_string($keysFile) || !file_exists($keysFile)) {
            $dataPath = getenv('SOFT_SERVE_DATA_PATH') ?: dirname(__DIR__, 3) . '/.data';
            $defaultFile = rtrim($dataPath, '/') . '/authorized_keys';
            if (file_exists($defaultFile)) {
                $keysFile = $defaultFile;
            }
        }

        if ($keysFile !== null && is_string($keysFile) && file_exists($keysFile)) {
            $lines = file($keysFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $rawLine) {
                    $trimmed = trim($rawLine);
                    if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                        continue;
                    }

                    try {
                        $formatted = $this->formatAuthorizedKeyLine($trimmed, $binConsole);
                        $output->writeln($formatted);
                    } catch (InvalidArgumentException) {
                        // Skip malformed key lines
                    }
                }
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Format a public key into an OpenSSH AuthorizedKeysCommand line with restricted command dispatch.
     *
     * Example output:
     * command="<binConsole> soft:ssh:dispatch --key-fingerprint=<fp>",no-port-forwarding,no-X11-forwarding,no-agent-forwarding <pubkey>
     */
    public function formatAuthorizedKeyLine(string $pubkey, ?string $binConsole = null): string
    {
        $bin = $binConsole !== null && trim($binConsole) !== '' ? trim($binConsole) : dirname(__DIR__, 2) . '/bin/console';

        $parsed = $this->comparator->parse($pubkey);
        if ($parsed === null) {
            throw new InvalidArgumentException(sprintf('Cannot parse public key: "%s"', $pubkey));
        }

        $fp = $this->comparator->fingerprintSha256($pubkey);

        $cleanPubkey = $parsed['type'] . ' ' . $parsed['key'];
        if ($parsed['comment'] !== null && $parsed['comment'] !== '') {
            $cleanPubkey .= ' ' . $parsed['comment'];
        }

        return sprintf(
            'command="%s soft:ssh:dispatch --key-fingerprint=%s",no-port-forwarding,no-X11-forwarding,no-agent-forwarding %s',
            $bin,
            $fp,
            $cleanPubkey
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Ssh\SshCommandDispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'soft:ssh:dispatch',
    description: 'Dispatch an incoming SSH session or SSH_ORIGINAL_COMMAND'
)]
final class SshDispatchCommand extends Command
{
    public function __construct(
        private readonly SshCommandDispatcher $dispatcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
         ->addArgument(
             'ssh-command',
             InputArgument::OPTIONAL,
             'Raw SSH original command string to dispatch (defaults to SSH_ORIGINAL_COMMAND env)',
             null
         )
         ->addOption(
             'key-fingerprint',
             'k',
             InputOption::VALUE_OPTIONAL,
             'SHA-256 fingerprint of the authenticated SSH public key',
             null
         )
         ->addOption(
             'target-repo',
             't',
             InputOption::VALUE_OPTIONAL,
             'Initial repository to open in interactive TUI',
             null
         )
         ->addOption(
             'user',
             'u',
             InputOption::VALUE_OPTIONAL,
             'Authenticated system or soft-serve username',
             null
         );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        // Resolve raw command from argument or OpenSSH environment
        $rawCommand = $input->getArgument('ssh-command');
        if (null === $rawCommand || '' === trim((string) $rawCommand)) {
            $rawCommand = $_SERVER['SSH_ORIGINAL_COMMAND'] ?? getenv('SSH_ORIGINAL_COMMAND') ?: '';
        }

        $rawCommand = (string) $rawCommand;

        // If command is empty and target repo was passed as option, build -t argument
        $targetRepo = $input->getOption('target-repo');
        if ('' === trim($rawCommand) && null !== $targetRepo && is_string($targetRepo) && '' !== trim($targetRepo)) {
            $rawCommand = '-t '.escapeshellarg(trim($targetRepo));
        }

        try {
            $action = $this->dispatcher->dispatch($rawCommand);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $errOutput->writeln(sprintf('<error>fatal: %s</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        $keyFingerprint = $input->getOption('key-fingerprint');
        $user = $input->getOption('user');

        return match ($action['type']) {
            'tui' => $this->runTui($action['repo'], $output, $errOutput),
            'git-pack' => $this->dispatcher->executeGitPack($action),
            'git-lfs-transfer', 'git-lfs-authenticate' => $this->runGitLfs($action, $output, $user, $keyFingerprint),
            'cli' => $this->runCli($action, $output, $errOutput, $user, $keyFingerprint),
            default => $this->handleUnknownAction($action, $errOutput),
        };
    }

    private function runTui(?string $initialRepo, OutputInterface $output, OutputInterface $errOutput): int
    {
        $app = $this->getApplication();
        if (null === $app) {
            $output->writeln('Interactive TUI dispatched (no console application attached).');

            return Command::SUCCESS;
        }

        try {
            $tuiCmd = $app->find('soft:tui');
            $tuiInput = new ArrayInput([
                'initial-repo' => $initialRepo,
            ]);

            return $tuiCmd->run($tuiInput, $output);
        } catch (\Throwable $e) {
            $errOutput->writeln(sprintf('<error>Failed to launch TUI: %s</error>', $e->getMessage()));

            return Command::FAILURE;
        }
    }

    /**
     * @param array<string, mixed> $action
     */
    private function runCli(
        array $action,
        OutputInterface $output,
        OutputInterface $errOutput,
        ?string $username = null,
        ?string $keyFingerprint = null,
    ): int {
        try {
            $result = $this->dispatcher->executeCli($action, $username, $keyFingerprint);
            $output->write($result);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $errOutput->writeln(sprintf('<error>fatal: %s</error>', $e->getMessage()));

            return Command::FAILURE;
        }
    }

    /**
     * @param array<string, mixed> $action
     */
    private function runGitLfs(
        array $action,
        OutputInterface $output,
        ?string $username = null,
        ?string $keyFingerprint = null,
    ): int {
        if ('git-lfs-authenticate' === $action['type']) {
            $repo = (string) $action['repo'];
            $operation = (string) ($action['operation'] ?? 'download');
            $jwt = $this->dispatcher->generateLfsToken($repo, $operation, $username, $keyFingerprint);
            $output->writeln((string) json_encode([
                'header' => ['Authorization' => 'Bearer '.$jwt],
                'href' => sprintf('http://localhost:8080/%s.git/info/lfs', $repo),
                'expires_in' => 3600,
            ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $output->writeln(sprintf("Git LFS operation '%s' on '%s' acknowledged.", $action['operation'] ?? 'unknown', $action['repo']));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $action
     */
    private function handleUnknownAction(array $action, OutputInterface $errOutput): int
    {
        $errOutput->writeln(sprintf('<error>Unhandled action type: %s</error>', $action['type'] ?? 'unknown'));

        return Command::FAILURE;
    }
}

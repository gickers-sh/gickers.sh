<?php

declare(strict_types=1);

namespace App\UI\GitDaemon;

use App\Shared\Infrastructure\Config\ServerSettings;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `soft:git-daemon` — the anonymous git:// TCP server (Go `pkg/daemon/daemon.go`).
 *
 * Accepts connections on `ServerSettings::$gitListenAddr`, enforces the max-connection
 * ceiling, and forks a child per connection that runs {@see GitDaemonProtocol::handle}.
 * Children drop the inherited Doctrine handles so each gets its own DB connection,
 * mirroring Go's per-connection goroutine with a fresh backend context.
 */
#[AsCommand(
    name: 'soft:git-daemon',
    description: 'Serve anonymous git:// fetch/clone over TCP (Go pkg/daemon).',
    aliases: ['git-daemon'],
)]
final class GitDaemonCommand extends Command
{
    /** Go `Git.IdleTimeout`: per-connection idle read deadline. */
    private const DEFAULT_IDLE_TIMEOUT = 3;

    /** Accept wakes up this often so signal-driven shutdown stays responsive. */
    private const ACCEPT_TIMEOUT = 1;

    private bool $running = true;
    private int $active = 0;

    public function __construct(
        private readonly GitDaemonProtocol $protocol,
        private readonly ServerSettings $settings,
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('listen', null, InputOption::VALUE_REQUIRED, 'Override the listen address (host:port or :port).')
            ->addOption('idle-timeout', null, InputOption::VALUE_REQUIRED, 'Per-connection idle read timeout in seconds.', (string) self::DEFAULT_IDLE_TIMEOUT)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Bind the listen address, report it, then exit without serving.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        [$host, $port] = $this->parseListenAddr((string) ($input->getOption('listen') ?: $this->settings->gitListenAddr));

        $errno = 0;
        $errstr = '';
        $server = @stream_socket_server(sprintf('tcp://%s:%d', $host, $port), $errno, $errstr);
        if (false === $server) {
            $stderr->writeln(sprintf('<error>git daemon: cannot bind %s:%d: %s</error>', $host, $port, '' !== $errstr ? $errstr : 'unknown error'));

            return Command::FAILURE;
        }

        if ($input->getOption('dry-run')) {
            $output->writeln(sprintf('<info>git daemon: would listen on %s:%d</info>', $host, $port));
            fclose($server);

            return Command::SUCCESS;
        }

        if (!\function_exists('pcntl_fork')) {
            $stderr->writeln('<error>git daemon: the pcntl extension is required to serve connections.</error>');
            fclose($server);

            return Command::FAILURE;
        }

        $idleTimeout = max(0, (int) $input->getOption('idle-timeout'));
        $maxConnections = $this->settings->gitMaxConnections;

        $this->installSignalHandlers();
        $output->writeln(sprintf('<info>git daemon listening on %s:%d (max %d connections)</info>', $host, $port, $maxConnections));

        while ($this->running) {
            if (\function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $conn = @stream_socket_accept($server, self::ACCEPT_TIMEOUT);
            if (false === $conn) {
                // Accept timeout or a signal interruption: loop to re-check $running.
                continue;
            }

            // Go: refuse when conns.Size()+1 >= MaxConnections.
            if ($maxConnections > 0 && $this->active + 1 >= $maxConnections) {
                @fwrite($conn, GitDaemonProtocol::errorPacket(GitDaemonProtocol::ERR_MAX_CONNECTIONS));
                fclose($conn);
                continue;
            }

            $pid = pcntl_fork();
            if (-1 === $pid) {
                @fwrite($conn, GitDaemonProtocol::errorPacket(GitDaemonProtocol::ERR_SYSTEM_MALFUNCTION));
                fclose($conn);
                continue;
            }

            if (0 === $pid) {
                $this->serveChild($server, $conn, $idleTimeout);
            }

            ++$this->active;
            fclose($conn); // parent retains only the listener
        }

        fclose($server);
        $this->reapChildren();

        return Command::SUCCESS;
    }

    /**
     * Child process: serve exactly one connection, then exit. Never returns.
     *
     * @param resource $server
     * @param resource $conn
     */
    private function serveChild($server, $conn, int $idleTimeout): never
    {
        fclose($server); // the child never accepts new connections

        // Forked children share the parent's socket/handles; give this process its own
        // DB connection and a clean identity map before touching the database.
        $this->entityManager->clear();
        $this->connection->close();

        if ($idleTimeout > 0) {
            stream_set_timeout($conn, $idleTimeout);
        }

        try {
            $this->protocol->handle($conn, $conn, $conn);
        } catch (\Throwable) {
            // A crashing child must never leak a PHP stack trace onto the git socket.
        } finally {
            if (\is_resource($conn)) {
                @fclose($conn);
            }
        }

        exit(0);
    }

    private function installSignalHandlers(): void
    {
        if (\function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        pcntl_signal(\SIGCHLD, fn(): null => $this->onChildExit());
        pcntl_signal(\SIGTERM, fn(): null => $this->onShutdown());
        pcntl_signal(\SIGINT, fn(): null => $this->onShutdown());
    }

    private function onChildExit(): null
    {
        $this->reapChildren();

        return null;
    }

    private function onShutdown(): null
    {
        $this->running = false;

        return null;
    }

    /** Non-blocking reap of finished children, keeping the active counter honest. */
    private function reapChildren(): void
    {
        if (!\function_exists('pcntl_waitpid')) {
            return;
        }

        $status = 0;
        while (($pid = pcntl_waitpid(-1, $status, \WNOHANG)) > 0) {
            $this->active = max(0, $this->active - 1);
        }
    }

    /**
     * Splits a Go-style listen address. Host defaults to `0.0.0.0` when omitted
     * (`:9418`), matching `cfg.Git.ListenAddr`.
     *
     * @return array{0: string, 1: int}
     */
    private function parseListenAddr(string $addr): array
    {
        $addr = trim($addr);
        $colon = strrpos($addr, ':');
        if (false === $colon) {
            return ['0.0.0.0', ctype_digit($addr) ? (int) $addr : 9418];
        }

        $host = substr($addr, 0, $colon);
        $port = (int) substr($addr, $colon + 1);

        return ['' !== $host ? $host : '0.0.0.0', $port > 0 ? $port : 9418];
    }
}

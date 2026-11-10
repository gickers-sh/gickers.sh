<?php

declare(strict_types=1);

namespace App\UI\Console\Command;

use App\Access\Application\ServerPolicy;
use App\Access\Domain\AccessLevel;
use App\Identity\Application\BootstrapServer;
use App\Identity\Infrastructure\Ssh\AuthorizedKeysFileWriter;
use App\Repo\Infrastructure\Git\HookSynchronizer;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\Shared\Infrastructure\Server\DataDirectoryInitializer;
use App\Shared\Infrastructure\Server\HostKeyGenerator;
use App\Shared\Infrastructure\Server\JwtKeyGenerator;
use App\Shared\Infrastructure\Server\ListenAddress;
use App\Shared\Infrastructure\Server\ProcessSupervisor;
use App\Shared\Infrastructure\Server\SshdConfigWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * `soft serve`: prepares the data directory, database, keys and sshd configuration,
 * then supervises sshd, the HTTP server and the Messenger worker.
 * Listen addresses come from the SOFT_SERVE_* environment variables.
 */
#[AsCommand(
    name: 'soft:serve',
    description: 'Start the server (SSH, HTTP and background worker)',
)]
final class ServeCommand extends Command
{
    public function __construct(
        private readonly ServerSettings $settings,
        private readonly ServerPolicy $serverPolicy,
        private readonly DataDirectoryInitializer $dataDirectory,
        private readonly HostKeyGenerator $hostKeys,
        private readonly JwtKeyGenerator $jwtKeys,
        private readonly SshdConfigWriter $sshdConfig,
        private readonly AuthorizedKeysFileWriter $authorizedKeys,
        private readonly BootstrapServer $bootstrapServer,
        private readonly HookSynchronizer $hookSynchronizer,
        private readonly ProcessSupervisor $supervisor,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire(env: 'DATABASE_URL')]
        private readonly string $databaseUrl,
        #[Autowire(env: 'MESSENGER_TRANSPORT_DSN')]
        private readonly string $messengerDsn,
        #[Autowire(env: 'SOFT_SERVE_WEBHOOK_ALLOW_LIST')]
        private readonly string $webhookAllowList,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('sync-hooks', null, InputOption::VALUE_NONE, 'Synchronize the hooks of every repository before starting')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Prepare everything, print the plan, and exit without starting processes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $this->dataDirectory->initialize();
        $this->runConsole('doctrine:migrations:migrate', ['--allow-no-migration' => true], $output);
        // Every SSH session and git hook boots bin/console: warm the cache once, up front.
        $this->runConsole('cache:warmup', [], $output);
        ($this->bootstrapServer)();

        if ($this->hostKeys->ensure()) {
            $output->writeln(sprintf('Generated SSH host key <info>%s</info>', $this->hostKeys->path()));
        }
        $this->jwtKeys->ensure();
        $this->authorizedKeys->write();

        $sshListen = ListenAddress::parse($this->settings->sshListenAddr, 23231);
        $httpListen = ListenAddress::parse($this->settings->httpListenAddr, 23232);
        $sshdConfigPath = $this->sshdConfig->write($sshListen, $this->hostKeys->path(), $this->authorizedKeys->path(), [
            'APP_ENV' => $this->environment,
            'DATABASE_URL' => $this->databaseUrl,
            'MESSENGER_TRANSPORT_DSN' => $this->messengerDsn,
            'SOFT_SERVE_WEBHOOK_ALLOW_LIST' => $this->webhookAllowList,
            ...$this->settings->toEnvironment(),
        ]);

        if ($input->getOption('sync-hooks')) {
            foreach ($this->hookSynchronizer->sync() as $name => $error) {
                if (null !== $error) {
                    $errOutput->writeln(sprintf('<error>sync-hooks %s: %s</error>', $name, $error->getMessage()));
                }
            }
        }

        if ($this->serverPolicy->allowKeyless() && AccessLevel::AdminAccess === $this->serverPolicy->anonAccess()) {
            $errOutput->writeln('<comment>WARNING: keyless connections are allowed with anonymous admin access</comment>');
        }

        $processes = $this->buildProcesses($sshdConfigPath, $httpListen, $errOutput);
        if (null === $processes) {
            return Command::FAILURE;
        }

        $output->writeln(sprintf('Data directory: <info>%s</info>', $this->settings->dataPath));
        $output->writeln(sprintf('SSH:        <info>%s</info> (%s)', $sshListen, $this->settings->sshPublicUrl));
        $output->writeln(sprintf('HTTP:       <info>%s</info> (%s)', $httpListen, $this->settings->httpPublicUrl));
        foreach ($processes as $name => $process) {
            $output->writeln(sprintf('  %-6s %s', $name, $process->getCommandLine()), OutputInterface::VERBOSITY_VERBOSE);
        }

        if ($input->getOption('dry-run')) {
            $output->writeln('<info>Dry run: nothing started.</info>');

            return Command::SUCCESS;
        }

        return $this->supervisor->run($processes, static function (string $name, string $type, string $buffer) use ($output, $errOutput): void {
            $target = Process::ERR === $type ? $errOutput : $output;
            foreach (explode("\n", rtrim($buffer, "\n")) as $line) {
                $target->writeln(sprintf('[%s] %s', $name, $line), OutputInterface::OUTPUT_RAW);
            }
        });
    }

    /**
     * @param array<string, bool|string> $options
     */
    private function runConsole(string $name, array $options, OutputInterface $output): void
    {
        $application = $this->getApplication() ?? throw new \LogicException('soft:serve must run inside the console application');
        $input = new ArrayInput(['command' => $name, ...$options]);
        $input->setInteractive(false);

        if (Command::SUCCESS !== $application->find($name)->run($input, $output)) {
            throw new \RuntimeException(sprintf('"%s" failed', $name));
        }
    }

    /**
     * @return array<string, Process>|null null when a required binary is missing
     */
    private function buildProcesses(string $sshdConfigPath, ListenAddress $httpListen, OutputInterface $errOutput): ?array
    {
        $finder = new ExecutableFinder();
        $console = $this->projectDir.'/bin/console';

        $sshd = $finder->find('sshd', null, ['/usr/sbin', '/usr/local/sbin']);
        if (null === $sshd) {
            $errOutput->writeln('<error>sshd not found (install OpenSSH server)</error>');

            return null;
        }

        $frankenphp = $finder->find('frankenphp');
        $http = null !== $frankenphp
            ? new Process([$frankenphp, 'run', '--config', $this->projectDir.'/Caddyfile'], $this->projectDir, ['SOFT_SERVE_HTTP_LISTEN_ADDR' => (string) $httpListen])
            // Development fallback: PHP's built-in server (single process, no worker mode).
            // variables_order=EGPCS fills $_ENV, otherwise Dotenv lets .env shadow the real environment.
            : new Process([\PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', (string) $httpListen, '-t', 'public', 'public/router.php'], $this->projectDir);
        if (null === $frankenphp) {
            $errOutput->writeln('<comment>frankenphp not found: serving HTTP with the PHP built-in server</comment>');
        }

        return [
            'sshd' => new Process([$sshd, '-D', '-e', '-f', $sshdConfigPath], $this->projectDir),
            'http' => $http,
            // `scheduler_default` is the transport Symfony derives from the #[AsSchedule('default')] provider (recurring jobs, e.g. mirror sync).
            'worker' => new Process([\PHP_BINARY, $console, 'messenger:consume', 'async', 'scheduler_default', '--time-limit=3600', '--memory-limit=128M'], $this->projectDir),
        ];
    }
}

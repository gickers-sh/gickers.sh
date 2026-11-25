<?php

declare(strict_types=1);

namespace App\UI\Console\Command;

use App\Access\Application\ServerPolicy;
use App\Access\Domain\AccessLevel;
use App\Deploy\Application\ReconcileDeployments;
use App\Identity\Application\BootstrapServer;
use App\Repo\Infrastructure\Git\HookSynchronizer;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\Shared\Infrastructure\Server\DataDirectoryInitializer;
use App\Shared\Infrastructure\Server\JwtKeyGenerator;
use App\Shared\Infrastructure\Server\ListenAddress;
use App\Shared\Infrastructure\Server\ProcessSupervisor;
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
 * `soft serve`: prepares the data directory, database and JWT key,
 * then supervises codes-ssh, the HTTP server and the Messenger worker.
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
        private readonly JwtKeyGenerator $jwtKeys,
        private readonly BootstrapServer $bootstrapServer,
        private readonly HookSynchronizer $hookSynchronizer,
        private readonly ProcessSupervisor $supervisor,
        private readonly ReconcileDeployments $reconcileDeployments,
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
        #[Autowire(env: 'default::CODES_SSH_BIN')]
        private readonly ?string $customCodesSshBin = null,
        #[Autowire(env: 'default::DEPLOY_AUTO_RECONCILE_ON_BOOT')]
        private readonly ?string $autoReconcileOnBoot = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('only', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Run only the given component(s): init, ssh, http, worker (repeatable). Default: all.')
            ->addOption('skip-init', null, InputOption::VALUE_NONE, 'Skip the one-time preparation (data dir, migrations, cache warmup, bootstrap, JWT keys)')
            ->addOption('sync-hooks', null, InputOption::VALUE_NONE, 'Synchronize the hooks of every repository before starting')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Prepare everything, print the plan, and exit without starting processes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $rawOnly = $input->getOption('only');
        $only = array_map(static fn (mixed $component): string => is_scalar($component) ? strtolower((string) $component) : '', is_array($rawOnly) ? $rawOnly : []);
        $known = ['init', 'ssh', 'http', 'worker'];
        foreach ($only as $component) {
            if (!in_array($component, $known, true)) {
                $errOutput->writeln(sprintf('<error>Unknown --only component "%s" (expected: %s)</error>', $component, implode(', ', $known)));

                return Command::FAILURE;
            }
        }

        if (!$input->getOption('skip-init')) {
            $this->dataDirectory->initialize();
            $this->runConsole('doctrine:migrations:migrate', ['--allow-no-migration' => true], $output);
            // Every SSH session and git hook boots bin/console: warm the cache once, up front.
            $this->runConsole('cache:warmup', [], $output);
            ($this->bootstrapServer)();
            $this->jwtKeys->ensure();

            $syncedCount = $this->reconcileDeployments->reconcileCaddyRoutes(reload: false);
            if ($syncedCount > 0) {
                $output->writeln(sprintf('<info>Reconciled Caddy reverse proxy routes for %d active deployment domain(s).</info>', $syncedCount));
            }

            if ($this->shouldAutoReconcileDeployOnBoot()) {
                $requeued = $this->reconcileDeployments->reconcileOnBoot();
                if ([] !== $requeued) {
                    $output->writeln(sprintf('<info>Requeued %d running deployment(s) for container reconciliation on boot.</info>', count($requeued)));
                }
            }
        }

        // `--only=init` runs the preparation above and exits (one-shot init container).
        if (['init'] === $only) {
            $output->writeln('<info>Initialization complete.</info>');

            return Command::SUCCESS;
        }

        $sshListen = ListenAddress::parse($this->settings->sshListenAddr, 23231);
        $httpListen = ListenAddress::parse($this->settings->httpListenAddr, 23232);

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

        $dryRun = (bool) $input->getOption('dry-run');
        $processes = $this->buildProcesses($sshListen, $httpListen, $dryRun, $errOutput);
        if (null === $processes) {
            return Command::FAILURE;
        }

        $selected = array_values(array_filter($only, static fn (string $component): bool => 'init' !== $component));
        if ([] !== $selected) {
            $processes = array_intersect_key($processes, array_flip($selected));
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
    private function buildProcesses(ListenAddress $sshListen, ListenAddress $httpListen, bool $dryRun, OutputInterface $errOutput): ?array
    {
        $finder = new ExecutableFinder();
        $console = $this->projectDir.'/bin/console';
        $codesSshBin = null !== $this->customCodesSshBin && '' !== $this->customCodesSshBin
            ? $this->customCodesSshBin
            : (is_executable('/usr/local/bin/codes-ssh') ? '/usr/local/bin/codes-ssh' : $this->projectDir.'/bin/codes-ssh');

        if (!is_executable($codesSshBin)) {
            if (!$dryRun) {
                $errOutput->writeln(sprintf('<error>codes-ssh not found at %s (run make build)</error>', $codesSshBin));

                return null;
            }
            $errOutput->writeln(sprintf('<comment>codes-ssh not found at %s (run make build before starting)</comment>', $codesSshBin));
        }

        $ssh = new Process([
            $codesSshBin,
            '--listen='.(string) $sshListen,
            '--host-key='.$this->settings->sshPath().'/soft_serve_host_ed25519',
            '--php='.\PHP_BINARY,
            '--console='.$console,
        ], $this->projectDir, [
            'APP_ENV' => $this->environment,
            'DATABASE_URL' => $this->databaseUrl,
            'MESSENGER_TRANSPORT_DSN' => $this->messengerDsn,
            'SOFT_SERVE_WEBHOOK_ALLOW_LIST' => $this->webhookAllowList,
            ...$this->settings->toEnvironment(),
        ]);

        $frankenphp = $finder->find('frankenphp');
        $http = null !== $frankenphp
            ? new Process([$frankenphp, 'run', '--config', $this->projectDir.'/Caddyfile'], $this->projectDir, [
                'SOFT_SERVE_HTTP_LISTEN_ADDR' => (string) $httpListen,
                'SOFT_SERVE_HTTP_PORT' => (string) $httpListen->port,
                'SOFT_SERVE_HTTP_HOST' => $httpListen->host,
            ])
            // Development fallback: PHP's built-in server (single process, no worker mode).
            // variables_order=EGPCS fills $_ENV, otherwise Dotenv lets .env shadow the real environment.
            : new Process([\PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', (string) $httpListen, '-t', 'public', 'public/router.php'], $this->projectDir);
        if (null === $frankenphp) {
            $errOutput->writeln('<comment>frankenphp not found: serving HTTP with the PHP built-in server</comment>');
        }

        return [
            'ssh' => $ssh,
            'http' => $http,
            // `scheduler_default` is the transport Symfony derives from the #[AsSchedule('default')] provider (recurring jobs, e.g. mirror sync).
            'worker' => new Process([\PHP_BINARY, '-d', 'memory_limit=512M', $console, 'messenger:consume', 'async', 'scheduler_default', '--time-limit=3600', '--memory-limit=256M'], $this->projectDir),
        ];
    }

    private function shouldAutoReconcileDeployOnBoot(): bool
    {
        if (null === $this->autoReconcileOnBoot || '' === trim($this->autoReconcileOnBoot)) {
            return true;
        }

        return filter_var($this->autoReconcileOnBoot, FILTER_VALIDATE_BOOLEAN);
    }
}

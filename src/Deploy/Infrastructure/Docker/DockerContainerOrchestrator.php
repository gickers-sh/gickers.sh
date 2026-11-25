<?php

declare(strict_types=1);

namespace App\Deploy\Infrastructure\Docker;

use App\Deploy\Application\GeneratedComposeBuilder;
use App\Deploy\Domain\ContainerOrchestrator;
use App\Deploy\Domain\DeployQuota;
use App\Deploy\Domain\Model\DeployEnvironment;
use App\Deploy\Domain\Spec\ComposeSpec;
use App\Deploy\Domain\Spec\ServiceSpec;
use App\Repo\Domain\RepoDirectory;
use App\Shared\Infrastructure\Config\ServerSettings;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[AsAlias(ContainerOrchestrator::class)]
final readonly class DockerContainerOrchestrator implements ContainerOrchestrator
{
    private const MAX_BUILD_ATTEMPTS = 3;

    private const TRANSIENT_ERROR_PATTERNS = [
        'i/o timeout',
        'TLS handshake timeout',
        'connection reset by peer',
        'temporary failure in name resolution',
        'cannot connect to the Docker daemon',
        '502 Bad Gateway',
        '503 Service Unavailable',
        '504 Gateway Timeout',
        'net/http: request canceled',
        'EOF',
    ];

    public function __construct(
        private ServerSettings $settings,
        private RepoDirectory $repoDirectory,
        private GeneratedComposeBuilder $composeBuilder,
        #[Autowire(env: 'default::DEPLOY_GATEWAY_HOST')]
        private ?string $deployGatewayHost = null,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function deploy(
        DeployEnvironment $environment,
        string $commitSha,
        ComposeSpec $spec,
        DeployQuota $quota,
        array $decryptedEnvVars,
        array $exposedServices,
        callable $onLog,
    ): void {
        $workDir = $this->environmentWorkDir($environment);
        if (is_dir($workDir)) {
            $this->filesystem->remove($workDir);
        }
        $this->filesystem->mkdir($workDir, 0o700);

        // 1. Export commit snapshot from bare git repository without any host working-tree sharing
        $barePath = $this->repoDirectory->pathFor($environment->repo()->name());
        $tarFile = $workDir.'/.commit.tar';

        $archiveProc = new Process(['git', 'archive', '--format=tar', '-o', $tarFile, $commitSha], $barePath);
        $archiveProc->setTimeout(120);
        $archiveProc->run();
        if (!$archiveProc->isSuccessful()) {
            throw new \RuntimeException(sprintf('git archive failed: %s', trim($archiveProc->getErrorOutput())));
        }

        $untarProc = new Process(['tar', '-xf', $tarFile, '-C', $workDir]);
        $untarProc->setTimeout(120);
        $untarProc->run();
        @unlink($tarFile);
        if (!$untarProc->isSuccessful()) {
            throw new \RuntimeException(sprintf('tar extraction failed: %s', trim($untarProc->getErrorOutput())));
        }

        // 2. For services that declared a relative bind-mount (e.g. ./:/app), ensure a wrapper Dockerfile
        // bakes the commit snapshot into the container if the service only specified an image.
        $adjustedServices = [];
        foreach ($spec->services as $name => $service) {
            $adjustedServices[$name] = $this->prepareRelativeMountBake($workDir, $service);
        }
        $adjustedSpec = new ComposeSpec(
            services: $adjustedServices,
            namedVolumes: $spec->namedVolumes,
            infoLogs: $spec->infoLogs,
        );

        // 3. Ensure external proxy network exists only when running in local single-engine fallback mode
        $useIsolatedGatewayPorts = null !== $this->deployGatewayHost && '' !== trim($this->deployGatewayHost);
        if (!$useIsolatedGatewayPorts && [] !== $exposedServices) {
            $this->ensureProxyNetwork();
        }

        // 4. Write 100% controlled compose.generated.yaml
        $generatedYaml = $this->composeBuilder->buildYaml(
            environment: $environment,
            spec: $adjustedSpec,
            quota: $quota,
            decryptedEnvVars: $decryptedEnvVars,
            exposedServices: $exposedServices,
        );
        $composeFile = $workDir.'/compose.generated.yaml';
        $this->filesystem->dumpFile($composeFile, $generatedYaml);

        // 5. Wait for the Docker deploy engine daemon to be ready (in case deploy-dockerd is still booting)
        $this->waitForDockerDaemon($onLog);

        // 6. Run docker compose --progress=plain up -d --build --remove-orphans (with smart retry for transient build/pull errors)
        $this->runComposeUpWithRetries($environment, $workDir, $composeFile, $onLog);

        // 7. Verify startup stability: check if any service crashed immediately or entered a crash-loop
        $this->verifyStartupStability($environment, $adjustedSpec, $onLog);
    }

    public function stop(DeployEnvironment $environment): void
    {
        $proc = new Process([
            'docker',
            'compose',
            '-p',
            $environment->projectSlug(),
            'stop',
        ]);
        $proc->setTimeout(120);
        $proc->run();
    }

    public function restart(DeployEnvironment $environment): void
    {
        $proc = new Process([
            'docker',
            'compose',
            '-p',
            $environment->projectSlug(),
            'restart',
        ]);
        $proc->setTimeout(120);
        $proc->run();
        if (!$proc->isSuccessful()) {
            throw new \RuntimeException(sprintf('failed to restart containers: %s', trim($proc->getErrorOutput())));
        }
    }

    public function destroy(DeployEnvironment $environment): void
    {
        $proc = new Process([
            'docker',
            'compose',
            '-p',
            $environment->projectSlug(),
            'down',
            '--volumes',
            '--remove-orphans',
        ]);
        $proc->setTimeout(180);
        $proc->run();

        $workDir = $this->environmentWorkDir($environment);
        if (is_dir($workDir)) {
            $this->filesystem->remove($workDir);
        }
    }

    public function serviceLogs(DeployEnvironment $environment, ?string $serviceName = null, int $tail = 200): string
    {
        $cmd = [
            'docker',
            'compose',
            '-p',
            $environment->projectSlug(),
            'logs',
            '--no-color',
            '--tail='.(string) $tail,
        ];
        if (null !== $serviceName && '' !== trim($serviceName)) {
            $cmd[] = trim($serviceName);
        }

        $proc = new Process($cmd);
        $proc->setTimeout(30);
        $proc->run();

        if (!$proc->isSuccessful()) {
            $err = trim($proc->getErrorOutput());
            if (str_contains(strtolower($err), 'cannot connect to the docker daemon')) {
                return '';
            }
        }

        return trim($proc->getOutput()."\n".$proc->getErrorOutput());
    }

    public function streamServiceLogs(DeployEnvironment $environment, ?string $serviceName, callable $onOutput, int $tail = 100): void
    {
        $cmd = [
            'docker',
            'compose',
            '-p',
            $environment->projectSlug(),
            'logs',
            '-f',
            '--no-color',
            '--tail='.(string) $tail,
        ];
        if (null !== $serviceName && '' !== trim($serviceName)) {
            $cmd[] = trim($serviceName);
        }

        $proc = new Process($cmd);
        $proc->setTimeout(null);
        $proc->run(static function ($type, $buffer) use ($onOutput): void {
            $onOutput($buffer);
        });
    }

    public function exec(DeployEnvironment $environment, string $serviceName, array $command): array
    {
        $cmd = [
            'docker',
            'compose',
            '-p',
            $environment->projectSlug(),
            'exec',
            '-T',
            $serviceName,
            ...$command,
        ];

        $proc = new Process($cmd);
        $proc->setTimeout(300);
        $proc->run();

        return [
            'exitCode' => $proc->getExitCode() ?? 1,
            'output' => $proc->getOutput().$proc->getErrorOutput(),
        ];
    }

    public function inspectServices(DeployEnvironment $environment): array
    {
        $psProc = new Process([
            'docker',
            'compose',
            '-p',
            $environment->projectSlug(),
            'ps',
            '-a',
            '-q',
        ]);
        $psProc->setTimeout(15);
        $psProc->run();
        if (!$psProc->isSuccessful()) {
            return [];
        }

        $containerIds = array_values(array_filter(
            array_map('trim', explode("\n", trim($psProc->getOutput()))),
            static fn (string $id): bool => '' !== $id,
        ));

        if ([] === $containerIds) {
            return [];
        }

        $inspectProc = new Process([
            'docker',
            'inspect',
            '--format',
            '{{index .Config.Labels "com.docker.compose.service"}}|{{.State.Status}}|{{.State.ExitCode}}|{{.RestartCount}}|{{.State.OOMKilled}}|{{.State.Error}}',
            ...$containerIds,
        ]);
        $inspectProc->setTimeout(15);
        $inspectProc->run();
        if (!$inspectProc->isSuccessful()) {
            return [];
        }

        $results = [];
        foreach (explode("\n", trim($inspectProc->getOutput())) as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            $parts = explode('|', $line, 6);
            if (\count($parts) < 6) {
                continue;
            }
            $results[] = [
                'service' => '' !== $parts[0] ? $parts[0] : 'app',
                'status' => $parts[1],
                'exitCode' => (int) $parts[2],
                'restartCount' => (int) $parts[3],
                'oomKilled' => 'true' === strtolower($parts[4]),
                'error' => $parts[5],
            ];
        }

        return $results;
    }

    /**
     * Waits up to 30 seconds for the isolated Docker daemon (`deploy-dockerd`) to accept connections
     * before running `docker compose up`, avoiding spurious deployment failures right after a stack restart/rebuild.
     *
     * @param callable(string):void $onLog
     */
    private function waitForDockerDaemon(callable $onLog): void
    {
        $maxWaitSeconds = 30;
        $loggedWaiting = false;

        for ($elapsed = 0; $elapsed < $maxWaitSeconds; $elapsed += 2) {
            $ping = new Process(['docker', 'info']);
            $ping->setTimeout(5);
            $ping->run();
            if ($ping->isSuccessful()) {
                return;
            }

            if (!$loggedWaiting) {
                $onLog('==> Waiting for isolated Docker deploy engine (deploy-dockerd) to become ready...');
                $loggedWaiting = true;
            }
            sleep(2);
        }
    }

    /**
     * Executes `docker compose up -d --build --remove-orphans` with automatic exponential backoff
     * when a transient network or registry error occurs, while failing fast on permanent build errors.
     *
     * @param callable(string):void $onLog
     */
    private function runComposeUpWithRetries(
        DeployEnvironment $environment,
        string $workDir,
        string $composeFile,
        callable $onLog,
    ): void {
        for ($attempt = 1; $attempt <= self::MAX_BUILD_ATTEMPTS; ++$attempt) {
            if (1 === $attempt) {
                $onLog(sprintf('==> Building and starting containers (project %s)...', $environment->projectSlug()));
            } else {
                $backoffSeconds = 2 ** ($attempt - 1);
                $onLog(sprintf(
                    '==> [retry %d/%d] Transient error detected; waiting %ds before retrying container build...',
                    $attempt,
                    self::MAX_BUILD_ATTEMPTS,
                    $backoffSeconds,
                ));
                sleep($backoffSeconds);
            }

            $capturedOutput = '';
            $upProc = new Process([
                'docker',
                'compose',
                '--progress=plain',
                '-p',
                $environment->projectSlug(),
                '-f',
                $composeFile,
                'up',
                '-d',
                '--build',
                '--remove-orphans',
            ], $workDir, [
                'BUILDKIT_PROGRESS' => 'plain',
            ]);
            $upProc->setTimeout(900);
            $upProc->run(static function (string $type, string $buffer) use ($onLog, &$capturedOutput): void {
                $capturedOutput .= $buffer;
                foreach (explode("\n", rtrim($buffer, "\n")) as $line) {
                    if ('' !== trim($line)) {
                        $onLog($line);
                    }
                }
            });

            if ($upProc->isSuccessful()) {
                return;
            }

            $exitCode = $upProc->getExitCode() ?? 1;
            if ($attempt < self::MAX_BUILD_ATTEMPTS && $this->isTransientComposeError($capturedOutput)) {
                continue;
            }

            throw new \RuntimeException(sprintf('docker compose up failed (exit %d)', $exitCode));
        }
    }

    private function isTransientComposeError(string $output): bool
    {
        $lower = strtolower($output);
        foreach (self::TRANSIENT_ERROR_PATTERNS as $pattern) {
            if (str_contains($lower, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Waits briefly after `docker compose up -d` and inspects container states to detect
     * immediate startup crashes or rapid restart loops before marking the release as Succeeded.
     *
     * @param callable(string):void $onLog
     */
    private function verifyStartupStability(DeployEnvironment $environment, ComposeSpec $spec, callable $onLog): void
    {
        // Give containers a brief window (1.5s) to initialize and surface immediate boot errors
        usleep(1_500_000);

        $states = $this->inspectServices($environment);
        if ([] === $states) {
            return;
        }

        foreach ($states as $state) {
            $svcName = $state['service'];
            $svcSpec = $spec->services[$svcName] ?? null;
            $isOneShotAllowed = null !== $svcSpec && 'no' === $svcSpec->restart;

            $crashed = false;
            $reason = '';

            if ($state['oomKilled']) {
                $crashed = true;
                $reason = sprintf('service "%s" was killed due to Out-Of-Memory (OOMKilled)', $svcName);
            } elseif (\in_array($state['status'], ['exited', 'dead'], true)) {
                if (0 !== $state['exitCode'] || !$isOneShotAllowed) {
                    $crashed = true;
                    $reason = sprintf('service "%s" exited immediately after startup (status: %s, exit code: %d, restarts: %d)', $svcName, $state['status'], $state['exitCode'], $state['restartCount']);
                }
            } elseif ('restarting' === $state['status'] || $state['restartCount'] >= 2) {
                $crashed = true;
                $reason = sprintf('service "%s" is crash-looping (status: %s, last exit code: %d, restarts: %d)', $svcName, $state['status'], $state['exitCode'], $state['restartCount']);
            }

            if ($crashed) {
                $logs = $this->serviceLogs($environment, $svcName, 50);
                if ('' !== $logs) {
                    $onLog(sprintf('==> Container logs for failing service "%s":', $svcName));
                    foreach (explode("\n", $logs) as $logLine) {
                        $onLog('  '.$logLine);
                    }
                }
                $onLog(sprintf('==> Stopping crash-looping environment "%s"...', $environment->projectSlug()));
                $this->stop($environment);

                throw new \RuntimeException($reason);
            }
        }
    }

    private function environmentWorkDir(DeployEnvironment $environment): string
    {
        return $this->settings->deployPath().'/'.$environment->projectSlug();
    }

    private function ensureProxyNetwork(): void
    {
        $inspect = new Process(['docker', 'network', 'inspect', GeneratedComposeBuilder::PROXY_NETWORK]);
        $inspect->run();
        if ($inspect->isSuccessful()) {
            return;
        }

        $create = new Process(['docker', 'network', 'create', GeneratedComposeBuilder::PROXY_NETWORK]);
        $create->run();
    }

    private function prepareRelativeMountBake(string $workDir, ServiceSpec $service): ServiceSpec
    {
        $relativeMounts = array_values(array_filter(
            $service->volumes,
            static fn ($m): bool => $m->isRelativeRepoPath,
        ));

        if ([] === $relativeMounts) {
            return $service;
        }

        // Case 1: Image-only service with relative bind-mounts (e.g. nginx mounting ./:/var/www/html)
        if (null === $service->build && null !== $service->image) {
            $synthDockerfile = sprintf('.Dockerfile.codes.%s', $service->name);
            $lines = [sprintf('FROM %s', $service->image)];
            foreach ($relativeMounts as $mount) {
                $src = '.' === $mount->source ? '.' : './'.$mount->source;
                $lines[] = sprintf('COPY %s %s', $src, $mount->target);
            }
            $this->filesystem->dumpFile($workDir.'/'.$synthDockerfile, implode("\n", $lines)."\n");

            return new ServiceSpec(
                name: $service->name,
                image: null,
                build: new \App\Deploy\Domain\Spec\BuildSpec(context: '.', dockerfile: $synthDockerfile),
                command: $service->command,
                entrypoint: $service->entrypoint,
                workingDir: $service->workingDir,
                environment: $service->environment,
                volumes: $service->volumes,
                dependsOn: $service->dependsOn,
                healthcheck: $service->healthcheck,
                restart: $service->restart,
            );
        }

        // Case 2: Service with a build.dockerfile AND relative bind-mounts (e.g. local-dev Dockerfile
        // that omits `COPY . /var/www/html` because it relies on `volumes: - ./:/var/www/html`).
        // Append the relative mount COPY instructions to the target Dockerfile so the commit snapshot is baked in.
        if (null !== $service->build) {
            $contextDir = '.' === $service->build->context ? $workDir : $workDir.'/'.$service->build->context;
            $dockerfilePath = $contextDir.'/'.$service->build->dockerfile;
            if (is_file($dockerfilePath)) {
                $existingContent = (string) file_get_contents($dockerfilePath);
                $appendLines = ['', '# Auto-injected by codes.sh Deploy Engine from relative compose volume mounts'];
                foreach ($relativeMounts as $mount) {
                    $src = '.' === $mount->source ? '.' : './'.$mount->source;
                    $appendLines[] = sprintf('COPY %s %s', $src, $mount->target);
                }
                $this->filesystem->dumpFile($dockerfilePath, rtrim($existingContent, "\n")."\n".implode("\n", $appendLines)."\n");
            }
        }

        return $service;
    }
}

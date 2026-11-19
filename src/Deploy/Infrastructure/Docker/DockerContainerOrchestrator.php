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
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[AsAlias(ContainerOrchestrator::class)]
final readonly class DockerContainerOrchestrator implements ContainerOrchestrator
{
    public function __construct(
        private ServerSettings $settings,
        private RepoDirectory $repoDirectory,
        private GeneratedComposeBuilder $composeBuilder,
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

        // 3. Ensure external proxy network exists if any service is exposed
        if ([] !== $exposedServices) {
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

        // 5. Run docker compose up -d --build --remove-orphans
        $onLog(sprintf('==> Building and starting containers (project %s)...', $environment->projectSlug()));
        $upProc = new Process([
            'docker',
            'compose',
            '-p',
            $environment->projectSlug(),
            '-f',
            $composeFile,
            'up',
            '-d',
            '--build',
            '--remove-orphans',
        ], $workDir);
        $upProc->setTimeout(900);
        $upProc->run(static function (string $type, string $buffer) use ($onLog): void {
            foreach (explode("\n", rtrim($buffer, "\n")) as $line) {
                if ('' !== trim($line)) {
                    $onLog($line);
                }
            }
        });

        if (!$upProc->isSuccessful()) {
            throw new \RuntimeException(sprintf('docker compose up failed (exit %d)', $upProc->getExitCode() ?? 1));
        }
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

        if ([] === $relativeMounts || null !== $service->build || null === $service->image) {
            return $service;
        }

        // Synthesize a Dockerfile that copies the relative repo paths into the base image
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
}

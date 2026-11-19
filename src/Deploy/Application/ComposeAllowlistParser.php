<?php

declare(strict_types=1);

namespace App\Deploy\Application;

use App\Deploy\Domain\DeployQuota;
use App\Deploy\Domain\Spec\BuildSpec;
use App\Deploy\Domain\Spec\ComposeSpec;
use App\Deploy\Domain\Spec\HealthcheckSpec;
use App\Deploy\Domain\Spec\ServiceSpec;
use App\Deploy\Domain\Spec\VolumeMountSpec;
use App\Shared\Domain\Exception\InvalidInput;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Strictly parses a compose.yaml (or synthesizes one from a root Dockerfile) using an explicit allowlist.
 *
 * Unknown or dangerous options (privileged, cap_add, network_mode, pid, devices, security_opt,
 * host bind-mounts) are rejected immediately. Local-dev convenience keys (`ports`, `expose`,
 * `develop`, `container_name`) are skipped with an `[info]` message.
 */
final readonly class ComposeAllowlistParser
{
    private const ALLOWED_TOP_LEVEL_KEYS = [
        'version',
        'name',
        'services',
        'volumes',
    ];

    private const ALLOWED_SERVICE_KEYS = [
        'image',
        'build',
        'command',
        'entrypoint',
        'working_dir',
        'environment',
        'volumes',
        'depends_on',
        'healthcheck',
        'restart',
    ];

    private const IGNORED_LOCAL_DEV_SERVICE_KEYS = [
        'ports',
        'expose',
        'develop',
        'container_name',
    ];

    private const ALLOWED_BUILD_KEYS = [
        'context',
        'dockerfile',
        'args',
        'target',
    ];

    private const ALLOWED_HEALTHCHECK_KEYS = [
        'test',
        'interval',
        'timeout',
        'retries',
        'start_period',
    ];

    public function parseDockerfileOnly(): ComposeSpec
    {
        $service = new ServiceSpec(
            name: 'app',
            build: new BuildSpec(context: '.', dockerfile: 'Dockerfile'),
        );

        return new ComposeSpec(
            services: ['app' => $service],
            namedVolumes: [],
            infoLogs: ['[info] Synthesized single-service specification ("app") from root Dockerfile.'],
        );
    }

    public function parseComposeYaml(string $yamlContent, DeployQuota $quota): ComposeSpec
    {
        try {
            $raw = Yaml::parse($yamlContent);
        } catch (ParseException $e) {
            throw new InvalidInput(sprintf('invalid compose.yaml syntax: %s', $e->getMessage()), 0, $e);
        }

        if (!\is_array($raw)) {
            throw new InvalidInput('compose.yaml must be a YAML mapping');
        }

        $infoLogs = [];

        foreach (array_keys($raw) as $topKey) {
            $topKeyStr = (string) $topKey;
            if (str_starts_with($topKeyStr, 'x-')) {
                continue;
            }
            if (!\in_array($topKeyStr, self::ALLOWED_TOP_LEVEL_KEYS, true)) {
                throw new InvalidInput(sprintf('disallowed top-level key "%s" in compose.yaml', $topKeyStr));
            }
        }

        if (!isset($raw['services']) || !\is_array($raw['services']) || [] === $raw['services']) {
            throw new InvalidInput('compose.yaml must define at least one service under "services"');
        }

        if (\count($raw['services']) > $quota->maxServices) {
            throw new InvalidInput(sprintf(
                'compose.yaml defines %d services, exceeding the quota limit of %d services',
                \count($raw['services']),
                $quota->maxServices,
            ));
        }

        $declaredVolumes = $this->parseTopLevelVolumes($raw['volumes'] ?? null);
        $services = [];
        $usedNamedVolumes = [];

        foreach ($raw['services'] as $serviceName => $serviceConfig) {
            $serviceNameStr = (string) $serviceName;
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$/', $serviceNameStr)) {
                throw new InvalidInput(sprintf('invalid service name "%s"', $serviceNameStr));
            }
            if (!\is_array($serviceConfig)) {
                throw new InvalidInput(sprintf('service "%s" definition must be a mapping', $serviceNameStr));
            }

            $services[$serviceNameStr] = $this->parseService(
                $serviceNameStr,
                $serviceConfig,
                $declaredVolumes,
                $usedNamedVolumes,
                $infoLogs,
            );
        }

        // Validate depends_on references
        foreach ($services as $service) {
            foreach ($service->dependsOn as $dep) {
                if (!isset($services[$dep])) {
                    throw new InvalidInput(sprintf('service "%s" depends on unknown service "%s"', $service->name, $dep));
                }
            }
        }

        $allNamedVolumes = array_values(array_unique(array_merge($declaredVolumes, $usedNamedVolumes)));
        sort($allNamedVolumes);

        return new ComposeSpec(
            services: $services,
            namedVolumes: $allNamedVolumes,
            infoLogs: $infoLogs,
        );
    }

    /**
     * @return list<string>
     */
    private function parseTopLevelVolumes(mixed $rawVolumes): array
    {
        if (null === $rawVolumes) {
            return [];
        }
        if (!\is_array($rawVolumes)) {
            throw new InvalidInput('top-level "volumes" must be a mapping');
        }

        $names = [];
        foreach ($rawVolumes as $volName => $volConfig) {
            $volNameStr = (string) $volName;
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$/', $volNameStr)) {
                throw new InvalidInput(sprintf('invalid volume name "%s"', $volNameStr));
            }
            if (null !== $volConfig && [] !== $volConfig) {
                throw new InvalidInput(sprintf('custom volume driver options on volume "%s" are not allowed', $volNameStr));
            }
            $names[] = $volNameStr;
        }

        return $names;
    }

    /**
     * @param array<mixed, mixed> $config
     * @param list<string>        $declaredVolumes
     * @param list<string>        $usedNamedVolumes
     * @param list<string>        $infoLogs
     */
    private function parseService(
        string $name,
        array $config,
        array $declaredVolumes,
        array &$usedNamedVolumes,
        array &$infoLogs,
    ): ServiceSpec {
        foreach (array_keys($config) as $key) {
            $keyStr = (string) $key;
            if (\in_array($keyStr, self::IGNORED_LOCAL_DEV_SERVICE_KEYS, true)) {
                $infoLogs[] = sprintf('[info] Service "%s": ignoring local-dev option "%s" (managed by codes.sh).', $name, $keyStr);
                continue;
            }
            if (!\in_array($keyStr, self::ALLOWED_SERVICE_KEYS, true)) {
                throw new InvalidInput(sprintf('service "%s": disallowed option "%s"', $name, $keyStr));
            }
        }

        $image = null;
        if (isset($config['image'])) {
            if (!\is_string($config['image']) || '' === trim($config['image'])) {
                throw new InvalidInput(sprintf('service "%s": "image" must be a non-empty string', $name));
            }
            $image = trim($config['image']);
        }

        $build = isset($config['build']) ? $this->parseBuild($name, $config['build']) : null;

        if (null === $image && null === $build) {
            throw new InvalidInput(sprintf('service "%s": must specify at least "image" or "build"', $name));
        }

        $command = isset($config['command']) ? $this->parseCommandOrEntrypoint($name, 'command', $config['command']) : null;
        $entrypoint = isset($config['entrypoint']) ? $this->parseCommandOrEntrypoint($name, 'entrypoint', $config['entrypoint']) : null;

        $workingDir = null;
        if (isset($config['working_dir'])) {
            if (!\is_string($config['working_dir']) || !str_starts_with($config['working_dir'], '/')) {
                throw new InvalidInput(sprintf('service "%s": "working_dir" must be an absolute container path', $name));
            }
            $workingDir = $config['working_dir'];
        }

        $environment = isset($config['environment']) ? $this->parseEnvironment($name, $config['environment']) : [];
        $volumes = isset($config['volumes']) ? $this->parseVolumes($name, $config['volumes'], $declaredVolumes, $usedNamedVolumes, $infoLogs) : [];
        $dependsOn = isset($config['depends_on']) ? $this->parseDependsOn($name, $config['depends_on']) : [];
        $healthcheck = isset($config['healthcheck']) ? $this->parseHealthcheck($name, $config['healthcheck']) : null;

        $restart = 'unless-stopped';
        if (isset($config['restart'])) {
            if (!\is_string($config['restart']) || !\in_array($config['restart'], ['no', 'always', 'on-failure', 'unless-stopped'], true)) {
                throw new InvalidInput(sprintf('service "%s": invalid "restart" policy', $name));
            }
            $restart = $config['restart'];
        }

        return new ServiceSpec(
            name: $name,
            image: $image,
            build: $build,
            command: $command,
            entrypoint: $entrypoint,
            workingDir: $workingDir,
            environment: $environment,
            volumes: $volumes,
            dependsOn: $dependsOn,
            healthcheck: $healthcheck,
            restart: $restart,
        );
    }

    private function parseBuild(string $serviceName, mixed $raw): BuildSpec
    {
        if (\is_string($raw)) {
            $context = $this->assertSafeRelativePath($serviceName, 'build context', $raw);

            return new BuildSpec(context: $context);
        }

        if (!\is_array($raw)) {
            throw new InvalidInput(sprintf('service "%s": "build" must be a string or mapping', $serviceName));
        }

        foreach (array_keys($raw) as $key) {
            $keyStr = (string) $key;
            if ('additional_contexts' === $keyStr) {
                throw new InvalidInput(sprintf('service "%s": "build.additional_contexts" is not allowed on server deployments', $serviceName));
            }
            if (!\in_array($keyStr, self::ALLOWED_BUILD_KEYS, true)) {
                throw new InvalidInput(sprintf('service "%s": disallowed build option "%s"', $serviceName, $keyStr));
            }
        }

        $context = isset($raw['context']) && \is_string($raw['context'])
            ? $this->assertSafeRelativePath($serviceName, 'build.context', $raw['context'])
            : '.';

        $dockerfile = isset($raw['dockerfile']) && \is_string($raw['dockerfile'])
            ? $this->assertSafeRelativePath($serviceName, 'build.dockerfile', $raw['dockerfile'])
            : 'Dockerfile';

        $args = isset($raw['args']) ? $this->parseKeyValueMap($serviceName, 'build.args', $raw['args']) : [];

        $target = null;
        if (isset($raw['target'])) {
            if (!\is_string($raw['target']) || !preg_match('/^[a-zA-Z0-9_.-]+$/', $raw['target'])) {
                throw new InvalidInput(sprintf('service "%s": invalid "build.target"', $serviceName));
            }
            $target = $raw['target'];
        }

        return new BuildSpec(
            context: $context,
            dockerfile: $dockerfile,
            args: $args,
            target: $target,
        );
    }

    private function assertSafeRelativePath(string $serviceName, string $field, string $path): string
    {
        $path = trim($path);
        if ('' === $path || str_starts_with($path, '/') || str_starts_with($path, '~') || str_contains($path, "\0")) {
            throw new InvalidInput(sprintf('service "%s": "%s" cannot be an absolute or home path ("%s")', $serviceName, $field, $path));
        }

        $normalized = str_replace('\\', '/', $path);
        $segments = explode('/', $normalized);
        foreach ($segments as $seg) {
            if ('..' === $seg) {
                throw new InvalidInput(sprintf('service "%s": "%s" cannot traverse outside the repository ("%s")', $serviceName, $field, $path));
            }
        }

        $trimmed = trim(ltrim($normalized, './'), '/');

        return '' === $trimmed ? '.' : $trimmed;
    }

    /**
     * @return list<string>|string
     */
    private function parseCommandOrEntrypoint(string $serviceName, string $field, mixed $raw): array|string
    {
        if (\is_string($raw)) {
            return $raw;
        }
        if (\is_array($raw)) {
            $list = [];
            foreach ($raw as $item) {
                if (!\is_scalar($item)) {
                    throw new InvalidInput(sprintf('service "%s": "%s" elements must be strings', $serviceName, $field));
                }
                $list[] = (string) $item;
            }

            return $list;
        }

        throw new InvalidInput(sprintf('service "%s": "%s" must be a string or list of strings', $serviceName, $field));
    }

    /**
     * @return array<string, string>
     */
    private function parseEnvironment(string $serviceName, mixed $raw): array
    {
        return $this->parseKeyValueMap($serviceName, 'environment', $raw);
    }

    /**
     * @return array<string, string>
     */
    private function parseKeyValueMap(string $serviceName, string $field, mixed $raw): array
    {
        if (!\is_array($raw)) {
            throw new InvalidInput(sprintf('service "%s": "%s" must be a mapping or list of KEY=VALUE', $serviceName, $field));
        }

        $result = [];
        foreach ($raw as $k => $v) {
            if (\is_int($k)) {
                if (!\is_string($v) || !str_contains($v, '=')) {
                    throw new InvalidInput(sprintf('service "%s": "%s" list items must be "KEY=VALUE"', $serviceName, $field));
                }
                [$key, $val] = explode('=', $v, 2);
                $result[trim($key)] = $val;
            } else {
                if (null !== $v && !\is_scalar($v)) {
                    throw new InvalidInput(sprintf('service "%s": "%s.%s" must be a scalar', $serviceName, $field, (string) $k));
                }
                $result[trim((string) $k)] = null === $v ? '' : (string) $v;
            }
        }

        return $result;
    }

    /**
     * @param list<string> $declaredVolumes
     * @param list<string> $usedNamedVolumes
     * @param list<string> $infoLogs
     *
     * @return list<VolumeMountSpec>
     */
    private function parseVolumes(
        string $serviceName,
        mixed $raw,
        array $declaredVolumes,
        array &$usedNamedVolumes,
        array &$infoLogs,
    ): array {
        if (!\is_array($raw)) {
            throw new InvalidInput(sprintf('service "%s": "volumes" must be a list', $serviceName));
        }

        $mounts = [];
        foreach ($raw as $item) {
            if (!\is_string($item)) {
                throw new InvalidInput(sprintf('service "%s": only short-syntax volume strings are allowed', $serviceName));
            }

            $parts = explode(':', $item);
            if (\count($parts) < 2 || \count($parts) > 3) {
                throw new InvalidInput(sprintf('service "%s": invalid volume syntax "%s" (expected source:target[:ro])', $serviceName, $item));
            }

            $source = trim($parts[0]);
            $target = trim($parts[1]);
            $mode = isset($parts[2]) ? trim($parts[2]) : 'rw';

            if (!str_starts_with($target, '/') || str_contains($target, '..')) {
                throw new InvalidInput(sprintf('service "%s": volume target "%s" must be an absolute path inside the container', $serviceName, $target));
            }

            if (!\in_array($mode, ['rw', 'ro'], true)) {
                throw new InvalidInput(sprintf('service "%s": invalid volume mode "%s" (only ro or rw allowed)', $serviceName, $mode));
            }

            $readOnly = 'ro' === $mode;

            // Check if it is a host path or relative path
            if (str_starts_with($source, '/') || str_starts_with($source, '~')) {
                throw new InvalidInput(sprintf('service "%s": host path volume "%s" is forbidden', $serviceName, $source));
            }

            if ('.' === $source || './' === $source || str_starts_with($source, './') || str_starts_with($source, '../') || str_contains($source, '/')) {
                $relPath = $this->assertSafeRelativePath($serviceName, 'volume source', $source);
                $infoLogs[] = sprintf(
                    '[info] Service "%s": converting relative bind-mount "%s:%s" to immutable repository commit snapshot.',
                    $serviceName,
                    $source,
                    $target,
                );
                $mounts[] = new VolumeMountSpec(
                    source: $relPath,
                    target: $target,
                    isRelativeRepoPath: true,
                    readOnly: $readOnly,
                );
                continue;
            }

            // Otherwise it must be a valid named volume
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$/', $source)) {
                throw new InvalidInput(sprintf('service "%s": invalid volume source "%s"', $serviceName, $source));
            }
            if ([] !== $declaredVolumes && !\in_array($source, $declaredVolumes, true)) {
                throw new InvalidInput(sprintf('service "%s": volume "%s" is not declared in top-level "volumes"', $serviceName, $source));
            }

            $usedNamedVolumes[] = $source;
            $mounts[] = new VolumeMountSpec(
                source: $source,
                target: $target,
                isRelativeRepoPath: false,
                readOnly: $readOnly,
            );
        }

        return $mounts;
    }

    /**
     * @return list<string>
     */
    private function parseDependsOn(string $serviceName, mixed $raw): array
    {
        if (!\is_array($raw)) {
            throw new InvalidInput(sprintf('service "%s": "depends_on" must be a list or mapping', $serviceName));
        }

        $deps = [];
        foreach ($raw as $k => $v) {
            if (\is_int($k)) {
                if (!\is_string($v)) {
                    throw new InvalidInput(sprintf('service "%s": "depends_on" items must be service names', $serviceName));
                }
                $deps[] = $v;
            } else {
                $deps[] = (string) $k;
            }
        }

        return array_values(array_unique($deps));
    }

    private function parseHealthcheck(string $serviceName, mixed $raw): HealthcheckSpec
    {
        if (!\is_array($raw)) {
            throw new InvalidInput(sprintf('service "%s": "healthcheck" must be a mapping', $serviceName));
        }

        foreach (array_keys($raw) as $key) {
            $keyStr = (string) $key;
            if (!\in_array($keyStr, self::ALLOWED_HEALTHCHECK_KEYS, true)) {
                throw new InvalidInput(sprintf('service "%s": disallowed healthcheck option "%s"', $serviceName, $keyStr));
            }
        }

        if (!isset($raw['test'])) {
            throw new InvalidInput(sprintf('service "%s": "healthcheck.test" is required', $serviceName));
        }

        $test = $this->parseCommandOrEntrypoint($serviceName, 'healthcheck.test', $raw['test']);
        $interval = isset($raw['interval']) && \is_string($raw['interval']) ? $raw['interval'] : null;
        $timeout = isset($raw['timeout']) && \is_string($raw['timeout']) ? $raw['timeout'] : null;
        $retries = isset($raw['retries']) && \is_int($raw['retries']) ? $raw['retries'] : null;
        $startPeriod = isset($raw['start_period']) && \is_string($raw['start_period']) ? $raw['start_period'] : null;

        return new HealthcheckSpec(
            test: $test,
            interval: $interval,
            timeout: $timeout,
            retries: $retries,
            startPeriod: $startPeriod,
        );
    }
}

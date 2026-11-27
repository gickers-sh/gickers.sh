<?php

declare(strict_types=1);

namespace App\Deploy\Application;

use App\Deploy\Domain\DeployQuota;
use App\Deploy\Domain\Model\DeployEnvironment;
use App\Deploy\Domain\Spec\ComposeSpec;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Generates a 100% controlled, security-hardened compose.generated.yaml from a validated ComposeSpec.
 */
final readonly class GeneratedComposeBuilder
{
    public const PROXY_NETWORK = 'codes-proxy';

    /**
     * Minimal safe Linux capabilities required by standard images (nginx, postgres, frankenphp, etc.)
     * after dropping ALL capabilities (which notably drops CAP_NET_RAW to prevent ARP/ICMP spoofing,
     * CAP_SYS_ADMIN, CAP_SYS_MODULE, CAP_SYS_PTRACE, CAP_NET_ADMIN, CAP_MKNOD, etc.).
     */
    private const SAFE_CAPABILITIES = [
        'CHOWN',
        'DAC_OVERRIDE',
        'FOWNER',
        'FSETID',
        'KILL',
        'NET_BIND_SERVICE',
        'SETGID',
        'SETPCAP',
        'SETUID',
        'SYS_CHROOT',
    ];

    public function __construct(
        #[Autowire(env: 'default::DEPLOY_DOCKER_RUNTIME')]
        private ?string $dockerRuntime = null,
        #[Autowire(env: 'default::DEPLOY_GATEWAY_HOST')]
        private ?string $deployGatewayHost = null,
    ) {
    }

    /**
     * @param array<string, string> $decryptedEnvVars
     * @param list<string>          $exposedServices  service names that have an active DeployDomain attached
     */
    public function buildYaml(
        DeployEnvironment $environment,
        ComposeSpec $spec,
        DeployQuota $quota,
        array $decryptedEnvVars,
        array $exposedServices,
    ): string {
        $projectSlug = $environment->projectSlug();
        $envNetwork = $environment->networkName();
        $exposedSet = array_flip($exposedServices);
        $useIsolatedGatewayPorts = null !== $this->deployGatewayHost && '' !== trim($this->deployGatewayHost);
        $runtime = null !== $this->dockerRuntime && '' !== trim($this->dockerRuntime) ? trim($this->dockerRuntime) : null;

        // Map service -> published gateway port mappings when using isolated deploy-dockerd gateway
        $serviceGatewayPorts = [];
        if ($useIsolatedGatewayPorts) {
            foreach ($environment->domains() as $domain) {
                $svc = $domain->serviceName();
                $mapping = sprintf('%d:%d', $domain->gatewayPort(), $domain->targetPort());
                $serviceGatewayPorts[$svc][$mapping] = $mapping;
            }
        }

        $services = [];
        foreach ($spec->services as $serviceName => $service) {
            $def = [];

            if (null !== $service->build) {
                $buildDef = [
                    'context' => self::escapeDollar('.' === $service->build->context ? '.' : './'.$service->build->context),
                    'dockerfile' => self::escapeDollar($service->build->dockerfile),
                ];
                if ([] !== $service->build->args) {
                    $buildDef['args'] = self::escapeStringMap($service->build->args);
                }
                if (null !== $service->build->target) {
                    $buildDef['target'] = self::escapeDollar($service->build->target);
                }
                $def['build'] = $buildDef;
                $def['image'] = sprintf('%s_%s:latest', $projectSlug, $serviceName);
            } elseif (null !== $service->image) {
                $def['image'] = self::escapeDollar($service->image);
            }

            if (null !== $runtime) {
                $def['runtime'] = $runtime;
            }

            if (null !== $service->command) {
                $def['command'] = self::escapeStringOrList($service->command);
            }
            if (null !== $service->entrypoint) {
                $def['entrypoint'] = self::escapeStringOrList($service->entrypoint);
            }
            if (null !== $service->workingDir) {
                $def['working_dir'] = self::escapeDollar($service->workingDir);
            }

            $mergedEnv = array_merge($service->environment, $decryptedEnvVars);
            if ([] !== $mergedEnv) {
                $def['environment'] = self::escapeStringMap($mergedEnv);
            }

            $volumes = [];
            foreach ($service->volumes as $mount) {
                if ($mount->isRelativeRepoPath) {
                    // Relative repo paths are baked into the image or ignored at runtime (no host bind-mount).
                    continue;
                }
                $prefixedVolume = sprintf('%s_%s', $projectSlug, $mount->source);
                $volumes[] = self::escapeDollar(sprintf('%s:%s%s', $prefixedVolume, $mount->target, $mount->readOnly ? ':ro' : ''));
            }
            if ([] !== $volumes) {
                $def['volumes'] = $volumes;
            }

            if ([] !== $service->dependsOn) {
                $def['depends_on'] = $service->dependsOn;
            }

            if (null !== $service->healthcheck) {
                $hc = ['test' => self::escapeStringOrList($service->healthcheck->test)];
                if (null !== $service->healthcheck->interval) {
                    $hc['interval'] = self::escapeDollar($service->healthcheck->interval);
                }
                if (null !== $service->healthcheck->timeout) {
                    $hc['timeout'] = self::escapeDollar($service->healthcheck->timeout);
                }
                if (null !== $service->healthcheck->retries) {
                    $hc['retries'] = $service->healthcheck->retries;
                }
                if (null !== $service->healthcheck->startPeriod) {
                    $hc['start_period'] = self::escapeDollar($service->healthcheck->startPeriod);
                }
                $def['healthcheck'] = $hc;
            }

            $def['restart'] = $service->restart;

            // Mandatory security and resource quota hardening
            $def['init'] = true;
            $def['security_opt'] = ['no-new-privileges:true'];
            $def['cap_drop'] = ['ALL'];
            $def['cap_add'] = self::SAFE_CAPABILITIES;
            $def['cpus'] = $quota->cpus;
            $def['mem_limit'] = $quota->memoryDockerString();
            $def['memswap_limit'] = $quota->memoryDockerString();
            $def['pids_limit'] = $quota->pids;
            $def['logging'] = [
                'driver' => 'json-file',
                'options' => [
                    'max-size' => '10m',
                    'max-file' => '3',
                ],
            ];

            if ($useIsolatedGatewayPorts && isset($serviceGatewayPorts[$serviceName])) {
                $def['ports'] = array_values($serviceGatewayPorts[$serviceName]);
            }

            // Strictly isolated per-environment network.
            // When DEPLOY_GATEWAY_HOST is configured (isolated DinD engine), no shared proxy_net is attached,
            // guaranteeing zero Layer-2 network sharing across deployed environments.
            $serviceNetworks = [
                'env_net' => [
                    'aliases' => [$serviceName],
                ],
            ];
            if (!$useIsolatedGatewayPorts && isset($exposedSet[$serviceName])) {
                $serviceNetworks['proxy_net'] = [
                    'aliases' => [$environment->proxyAlias($serviceName)],
                ];
            }
            $def['networks'] = $serviceNetworks;

            $services[$serviceName] = $def;
        }

        $doc = [
            'name' => $projectSlug,
            'services' => $services,
            'networks' => [
                'env_net' => [
                    'name' => $envNetwork,
                    'driver' => 'bridge',
                ],
            ],
        ];

        if (!$useIsolatedGatewayPorts && [] !== $exposedServices) {
            $doc['networks']['proxy_net'] = [
                'name' => self::PROXY_NETWORK,
                'external' => true,
            ];
        }

        if ([] !== $spec->namedVolumes) {
            $volumesDoc = [];
            foreach ($spec->namedVolumes as $vol) {
                $prefixedVolume = sprintf('%s_%s', $projectSlug, $vol);
                $volumesDoc[$prefixedVolume] = [
                    'name' => $prefixedVolume,
                ];
            }
            $doc['volumes'] = $volumesDoc;
        }

        return Yaml::dump($doc, 6, 2);
    }

    /**
     * Escapes `$` to `$$` so Docker Compose never interpolates worker/host environment variables.
     */
    private static function escapeDollar(string $value): string
    {
        return str_replace('$', '$$', $value);
    }

    /**
     * @param string|list<string> $value
     *
     * @return string|list<string>
     */
    private static function escapeStringOrList(string|array $value): string|array
    {
        if (\is_string($value)) {
            return self::escapeDollar($value);
        }

        return array_map(self::escapeDollar(...), $value);
    }

    /**
     * @param array<string, string> $map
     *
     * @return array<string, string>
     */
    private static function escapeStringMap(array $map): array
    {
        $escaped = [];
        foreach ($map as $k => $v) {
            $escaped[$k] = self::escapeDollar($v);
        }

        return $escaped;
    }
}

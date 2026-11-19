<?php

declare(strict_types=1);

namespace App\Deploy\Application;

use App\Deploy\Domain\DeployQuota;
use App\Deploy\Domain\Model\DeployEnvironment;
use App\Deploy\Domain\Spec\ComposeSpec;
use Symfony\Component\Yaml\Yaml;

/**
 * Generates a 100% controlled, security-hardened compose.generated.yaml from a validated ComposeSpec.
 */
final readonly class GeneratedComposeBuilder
{
    public const PROXY_NETWORK = 'codes-proxy';

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

        $services = [];
        foreach ($spec->services as $serviceName => $service) {
            $def = [];

            if (null !== $service->build) {
                $buildDef = [
                    'context' => '.' === $service->build->context ? '.' : './'.$service->build->context,
                    'dockerfile' => $service->build->dockerfile,
                ];
                if ([] !== $service->build->args) {
                    $buildDef['args'] = $service->build->args;
                }
                if (null !== $service->build->target) {
                    $buildDef['target'] = $service->build->target;
                }
                $def['build'] = $buildDef;
                $def['image'] = sprintf('%s_%s:latest', $projectSlug, $serviceName);
            } elseif (null !== $service->image) {
                $def['image'] = $service->image;
            }

            if (null !== $service->command) {
                $def['command'] = $service->command;
            }
            if (null !== $service->entrypoint) {
                $def['entrypoint'] = $service->entrypoint;
            }
            if (null !== $service->workingDir) {
                $def['working_dir'] = $service->workingDir;
            }

            $mergedEnv = array_merge($service->environment, $decryptedEnvVars);
            if ([] !== $mergedEnv) {
                $def['environment'] = $mergedEnv;
            }

            $volumes = [];
            foreach ($service->volumes as $mount) {
                if ($mount->isRelativeRepoPath) {
                    // Relative repo paths are baked into the image or ignored at runtime (no host bind-mount).
                    continue;
                }
                $prefixedVolume = sprintf('%s_%s', $projectSlug, $mount->source);
                $volumes[] = sprintf('%s:%s%s', $prefixedVolume, $mount->target, $mount->readOnly ? ':ro' : '');
            }
            if ([] !== $volumes) {
                $def['volumes'] = $volumes;
            }

            if ([] !== $service->dependsOn) {
                $def['depends_on'] = $service->dependsOn;
            }

            if (null !== $service->healthcheck) {
                $hc = ['test' => $service->healthcheck->test];
                if (null !== $service->healthcheck->interval) {
                    $hc['interval'] = $service->healthcheck->interval;
                }
                if (null !== $service->healthcheck->timeout) {
                    $hc['timeout'] = $service->healthcheck->timeout;
                }
                if (null !== $service->healthcheck->retries) {
                    $hc['retries'] = $service->healthcheck->retries;
                }
                if (null !== $service->healthcheck->startPeriod) {
                    $hc['start_period'] = $service->healthcheck->startPeriod;
                }
                $def['healthcheck'] = $hc;
            }

            $def['restart'] = $service->restart;

            // Mandatory security and resource quota hardening
            $def['security_opt'] = ['no-new-privileges:true'];
            $def['cpus'] = $quota->cpus;
            $def['mem_limit'] = $quota->memoryDockerString();
            $def['pids_limit'] = $quota->pids;

            // Isolated network + optional proxy network attachment if explicitly exposed
            $serviceNetworks = [
                'env_net' => [
                    'aliases' => [$serviceName],
                ],
            ];
            if (isset($exposedSet[$serviceName])) {
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

        if ([] !== $exposedServices) {
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
}

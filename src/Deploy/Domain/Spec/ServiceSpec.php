<?php

declare(strict_types=1);

namespace App\Deploy\Domain\Spec;

final readonly class ServiceSpec
{
    /**
     * @param list<string>|string|null $command
     * @param list<string>|string|null $entrypoint
     * @param array<string, string>    $environment
     * @param list<VolumeMountSpec>    $volumes
     * @param list<string>             $dependsOn
     */
    public function __construct(
        public string $name,
        public ?string $image = null,
        public ?BuildSpec $build = null,
        public array|string|null $command = null,
        public array|string|null $entrypoint = null,
        public ?string $workingDir = null,
        public array $environment = [],
        public array $volumes = [],
        public array $dependsOn = [],
        public ?HealthcheckSpec $healthcheck = null,
        public string $restart = 'unless-stopped',
    ) {
    }
}

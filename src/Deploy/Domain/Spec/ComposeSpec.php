<?php

declare(strict_types=1);

namespace App\Deploy\Domain\Spec;

final readonly class ComposeSpec
{
    /**
     * @param array<string, ServiceSpec> $services
     * @param list<string>               $namedVolumes
     * @param list<string>               $infoLogs
     */
    public function __construct(
        public array $services,
        public array $namedVolumes = [],
        public array $infoLogs = [],
    ) {
    }

    public function hasService(string $serviceName): bool
    {
        return isset($this->services[$serviceName]);
    }
}

<?php

declare(strict_types=1);

namespace App\Deploy\Domain\Spec;

final readonly class HealthcheckSpec
{
    /**
     * @param list<string>|string $test
     */
    public function __construct(
        public array|string $test,
        public ?string $interval = null,
        public ?string $timeout = null,
        public ?int $retries = null,
        public ?string $startPeriod = null,
    ) {
    }
}

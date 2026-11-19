<?php

declare(strict_types=1);

namespace App\Deploy\Domain;

use App\Shared\Domain\Exception\InvalidInput;

final readonly class DeployQuota
{
    public const DEFAULT_CPUS = 1.0;
    public const DEFAULT_MEMORY_MB = 512;
    public const DEFAULT_PIDS = 128;
    public const DEFAULT_MAX_ENVIRONMENTS = 3;
    public const DEFAULT_MAX_SERVICES = 4;

    public function __construct(
        public float $cpus = self::DEFAULT_CPUS,
        public int $memoryMb = self::DEFAULT_MEMORY_MB,
        public int $pids = self::DEFAULT_PIDS,
        public int $maxEnvironments = self::DEFAULT_MAX_ENVIRONMENTS,
        public int $maxServices = self::DEFAULT_MAX_SERVICES,
    ) {
        if ($cpus <= 0.0 || $cpus > 128.0) {
            throw new InvalidInput('cpus must be between 0.01 and 128');
        }
        if ($memoryMb < 16 || $memoryMb > 262144) {
            throw new InvalidInput('memory must be between 16m and 262144m');
        }
        if ($pids < 8 || $pids > 32768) {
            throw new InvalidInput('pids must be between 8 and 32768');
        }
        if ($maxEnvironments < 1 || $maxEnvironments > 100) {
            throw new InvalidInput('max-envs must be between 1 and 100');
        }
        if ($maxServices < 1 || $maxServices > 50) {
            throw new InvalidInput('max-services must be between 1 and 50');
        }
    }

    public static function parseMemoryMb(string $raw): int
    {
        $raw = strtolower(trim($raw));
        if (!preg_match('/^(\d+)\s*(m|mb|g|gb)?$/', $raw, $m)) {
            throw new InvalidInput(sprintf('invalid memory value "%s" (expected e.g. 512m or 2g)', $raw));
        }

        $val = (int) $m[1];
        $unit = $m[2] ?? 'm';

        return match ($unit) {
            'g', 'gb' => $val * 1024,
            default => $val,
        };
    }

    public function memoryDockerString(): string
    {
        return sprintf('%dm', $this->memoryMb);
    }
}

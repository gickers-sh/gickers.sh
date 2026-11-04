<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Config;

/**
 * Immutable server configuration resolved from environment variables (see config/packages/codes_sh.yaml).
 */
final readonly class ServerSettings
{
    /** @var list<string> */
    public array $initialAdminKeys;

    public function __construct(
        public string $name,
        public string $dataPath,
        public string $sshListenAddr,
        public string $sshPublicUrl,
        public string $httpListenAddr,
        public string $httpPublicUrl,
        public string $gitListenAddr,
        public int $gitMaxConnections,
        public bool $lfsEnabled,
        public string $anonAccess,
        public bool $allowKeyless,
        string $initialAdminKeys,
    ) {
        $keys = [];
        foreach (preg_split('/\\R/', $initialAdminKeys) ?: [] as $line) {
            $line = trim($line);
            if ('' !== $line && !str_starts_with($line, '#')) {
                $keys[] = $line;
            }
        }
        $this->initialAdminKeys = array_values(array_unique($keys));
    }

    public function reposPath(): string
    {
        return $this->dataPath.'/repos';
    }

    public function lfsPath(): string
    {
        return $this->dataPath.'/lfs';
    }

    public function hooksPath(): string
    {
        return $this->dataPath.'/hooks';
    }

    public function sshPath(): string
    {
        return $this->dataPath.'/ssh';
    }

    public function codeIntelPath(): string
    {
        return $this->dataPath.'/codeintel';
    }
}

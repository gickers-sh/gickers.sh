<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Config;

/**
 * Immutable server configuration resolved from environment variables (see config/packages/gickers_sh.yaml).
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

    public function deployPath(): string
    {
        return $this->dataPath.'/deploy';
    }

    public function caddySitesPath(): string
    {
        return $this->dataPath.'/caddy/sites';
    }

    /**
     * The SOFT_SERVE_* variables this object was built from (config/packages/gickers_sh.yaml),
     * for processes that do not inherit the environment (sshd sessions).
     *
     * @return array<string, string>
     */
    public function toEnvironment(): array
    {
        return [
            'SOFT_SERVE_NAME' => $this->name,
            'SOFT_SERVE_DATA_PATH' => $this->dataPath,
            'SOFT_SERVE_SSH_LISTEN_ADDR' => $this->sshListenAddr,
            'SOFT_SERVE_SSH_PUBLIC_URL' => $this->sshPublicUrl,
            'SOFT_SERVE_HTTP_LISTEN_ADDR' => $this->httpListenAddr,
            'SOFT_SERVE_HTTP_PUBLIC_URL' => $this->httpPublicUrl,
            'SOFT_SERVE_LFS_ENABLED' => $this->lfsEnabled ? 'true' : 'false',
            'SOFT_SERVE_ANON_ACCESS' => $this->anonAccess,
            'SOFT_SERVE_ALLOW_KEYLESS' => $this->allowKeyless ? 'true' : 'false',
        ];
    }
}

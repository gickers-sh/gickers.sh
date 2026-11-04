<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Server;

use App\Shared\Infrastructure\Config\ServerSettings;
use Symfony\Component\Process\Process;

/**
 * Generates the SSH host key `%data%/ssh/soft_serve_host_ed25519` on first start
 * (same file name as Go `cfg.SSH.KeyPath`).
 */
final readonly class HostKeyGenerator
{
    public function __construct(private ServerSettings $settings)
    {
    }

    public function path(): string
    {
        return $this->settings->sshPath().'/soft_serve_host_ed25519';
    }

    /**
     * @return bool true when a new key was generated
     */
    public function ensure(): bool
    {
        $path = $this->path();
        if (is_file($path)) {
            return false;
        }

        (new Process(['ssh-keygen', '-q', '-N', '', '-t', 'ed25519', '-C', 'soft-serve', '-f', $path]))->mustRun();
        chmod($path, 0o600);

        return true;
    }
}

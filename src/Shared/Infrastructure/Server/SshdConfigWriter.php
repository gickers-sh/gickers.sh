<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Server;

use App\Shared\Infrastructure\Config\ServerSettings;

/**
 * Writes the unprivileged OpenSSH daemon configuration `%data%/ssh/sshd_config`.
 * Only public-key auth is enabled; every key in `authorized_keys` carries the
 * forced `soft:ssh:dispatch` command.
 */
final readonly class SshdConfigWriter
{
    public function __construct(private ServerSettings $settings)
    {
    }

    /**
     * @param array<string, string> $sessionEnv variables set in every SSH session (sshd does not
     *                                          pass its own environment to the forced command)
     */
    public function write(ListenAddress $listen, string $hostKeyPath, string $authorizedKeysPath, array $sessionEnv = []): string
    {
        $sshDir = $this->settings->sshPath();
        $path = $sshDir.'/sshd_config';

        $lines = [
            'Port '.$listen->port,
            'ListenAddress '.$listen->host,
            'HostKey '.self::quote($hostKeyPath),
            'AuthorizedKeysFile '.self::quote($authorizedKeysPath),
            'PidFile '.self::quote($sshDir.'/sshd.pid'),
            'PasswordAuthentication no',
            'PubkeyAuthentication yes',
            'KbdInteractiveAuthentication no',
            'UsePAM no',
            'PermitUserEnvironment no',
            'AllowTcpForwarding no',
            'X11Forwarding no',
            'AcceptEnv LANG LC_* TERM COLORTERM',
            // sshd runs as the invoking user: data dirs are not root-owned.
            'StrictModes no',
            'LogLevel INFO',
        ];
        if ([] !== $sessionEnv) {
            $assignments = [];
            foreach ($sessionEnv as $name => $value) {
                $assignments[] = self::quote($name.'='.$value);
            }
            $lines[] = 'SetEnv '.implode(' ', $assignments);
        }

        if (false === file_put_contents($path, implode("\n", $lines)."\n")) {
            throw new \RuntimeException(sprintf('Failed to write "%s"', $path));
        }
        chmod($path, 0o600);

        return $path;
    }

    private static function quote(string $value): string
    {
        return '"'.str_replace('"', '\\"', $value).'"';
    }
}

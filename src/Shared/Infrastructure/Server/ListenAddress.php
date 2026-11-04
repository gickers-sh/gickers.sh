<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Server;

/**
 * Go-style listen address (`host:port`, `:port`, `[::1]:port` or a bare port).
 */
final readonly class ListenAddress
{
    public function __construct(
        public string $host,
        public int $port,
    ) {
    }

    public static function parse(string $addr, int $defaultPort, string $defaultHost = '0.0.0.0'): self
    {
        $addr = trim($addr);
        $colon = strrpos($addr, ':');
        if (false === $colon || str_ends_with($addr, ']')) {
            return new self(
                '' !== $addr && !ctype_digit($addr) ? trim($addr, '[]') : $defaultHost,
                ctype_digit($addr) ? (int) $addr : $defaultPort,
            );
        }

        $host = trim(substr($addr, 0, $colon), '[]');
        $port = (int) substr($addr, $colon + 1);

        return new self('' !== $host ? $host : $defaultHost, $port > 0 ? $port : $defaultPort);
    }

    public function __toString(): string
    {
        return sprintf(str_contains($this->host, ':') ? '[%s]:%d' : '%s:%d', $this->host, $this->port);
    }
}

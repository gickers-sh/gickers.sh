<?php

declare(strict_types=1);

namespace App\Service\Security;

/**
 * SsrfValidator protects against Server-Side Request Forgery by rejecting
 * private, loopback, link-local, multicast, metadata, and reserved IP addresses.
 * Port of pkg/ssrf/ssrf.go.
 */
class SsrfValidator
{
    /**
     * @var list<array{prefix: string, bits: int, v6: bool}>
     */
    private array $blockedPrefixes;

    public function __construct()
    {
        $this->blockedPrefixes = $this->compileBlockedPrefixes();
    }

    /**
     * Validates whether an IP address is safe (public) for outbound requests.
     * Returns true if safe/allowed, false if private/blocked or invalid.
     */
    public function validateIp(string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '') {
            return false;
        }

        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }

        return !$this->isBinaryIpBlocked($bin);
    }

    /**
     * Reports whether an IP is private, internal, or blocked.
     */
    public function isPrivateOrBlocked(string $ip): bool
    {
        return !$this->validateIp($ip);
    }

    /**
     * Validates that a URL is safe to make outbound HTTP requests to.
     * Rejects non-HTTP(S) schemes, localhost, and destinations resolving to private/blocked IPs.
     */
    public function validateUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $host = strtolower($parts['host']);
        if ($this->isLocalhost($host)) {
            return false;
        }

        // If host is an IPv6 literal enclosed in brackets e.g. [::1]
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        // If host is an IP address
        if (@inet_pton($host) !== false) {
            return $this->validateIp($host);
        }

        // Otherwise resolve hostname
        $resolvedIps = $this->resolveHost($host);
        if (empty($resolvedIps)) {
            return false;
        }

        foreach ($resolvedIps as $resolvedIp) {
            if (!$this->validateIp($resolvedIp)) {
                return false;
            }
        }

        return true;
    }

    private function isLocalhost(string $host): bool
    {
        return $host === 'localhost'
            || $host === 'localhost.localdomain'
            || str_ends_with($host, '.localhost');
    }

    /**
     * @return list<string>
     */
    private function resolveHost(string $host): array
    {
        $ips = [];

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    $ips[] = (string) $record['ip'];
                } elseif (isset($record['ipv6'])) {
                    $ips[] = (string) $record['ipv6'];
                }
            }
        }

        if (empty($ips)) {
            $byName = @gethostbynamel($host);
            if (is_array($byName)) {
                $ips = array_merge($ips, $byName);
            }
        }

        return array_values(array_unique($ips));
    }

    private function isBinaryIpBlocked(string $bin): bool
    {
        $isV6 = strlen($bin) === 16;

        // Unmap IPv4-mapped IPv6 (::ffff:127.0.0.1)
        if ($isV6 && substr($bin, 0, 12) === (str_repeat("\x00", 10) . "\xff\xff")) {
            $bin = substr($bin, 12);
            $isV6 = false;
        }

        foreach ($this->blockedPrefixes as $prefix) {
            if ($prefix['v6'] !== $isV6) {
                continue;
            }

            if ($this->matchCidr($bin, $prefix['prefix'], $prefix['bits'])) {
                return true;
            }
        }

        return false;
    }

    private function matchCidr(string $ipBin, string $prefixBin, int $maskBits): bool
    {
        $fullBytes = intdiv($maskBits, 8);
        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($prefixBin, 0, $fullBytes)) {
            return false;
        }

        $remBits = $maskBits % 8;
        if ($remBits > 0) {
            $maskByte = chr((0xFF << (8 - $remBits)) & 0xFF);
            if (($ipBin[$fullBytes] & $maskByte) !== ($prefixBin[$fullBytes] & $maskByte)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array{prefix: string, bits: int, v6: bool}>
     */
    private function compileBlockedPrefixes(): array
    {
        $cidrList = [
            // IPv4
            '0.0.0.0/8',       // "this network" / unspecified
            '10.0.0.0/8',      // RFC 1918 private
            '100.64.0.0/10',   // RFC 6598 shared address space (CGNAT)
            '127.0.0.0/8',     // loopback
            '169.254.0.0/16',  // link-local & cloud metadata
            '172.16.0.0/12',   // RFC 1918 private
            '192.0.0.0/24',    // IETF protocol assignments
            '192.0.2.0/24',    // TEST-NET-1
            '192.88.99.0/24',  // RFC 7526 6to4 relay anycast
            '192.168.0.0/16',  // RFC 1918 private
            '198.18.0.0/15',   // RFC 2544 benchmarking
            '198.51.100.0/24', // TEST-NET-2
            '203.0.113.0/24',  // TEST-NET-3
            '224.0.0.0/4',     // multicast
            '240.0.0.0/4',     // reserved / broadcast

            // IPv6
            '::1/128',         // loopback
            '64:ff9b::/96',    // RFC 6052 NAT64 well-known prefix
            '64:ff9b:1::/48',  // RFC 8215 NAT64 local-use prefix
            '100::/64',        // RFC 6666 discard-only
            '2001::/32',       // RFC 4380 Teredo
            '2001:db8::/32',   // documentation
            '2002::/16',       // RFC 3056 6to4
            'fc00::/7',        // unique local (ULA)
            'fe80::/10',       // link-local
            'ff00::/8',        // multicast
            '::/96',           // IPv4-compatible IPv6 (deprecated RFC 4291)
            '::/128',          // unspecified address
        ];

        $compiled = [];
        foreach ($cidrList as $cidr) {
            [$prefixIp, $bitsStr] = explode('/', $cidr, 2);
            $bits = (int) $bitsStr;
            $bin = inet_pton($prefixIp);
            if ($bin !== false) {
                $compiled[] = [
                    'prefix' => $bin,
                    'bits' => $bits,
                    'v6' => strlen($bin) === 16,
                ];
            }
        }

        return $compiled;
    }
}

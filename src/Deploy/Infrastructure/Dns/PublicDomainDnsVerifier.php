<?php

declare(strict_types=1);

namespace App\Deploy\Infrastructure\Dns;

use App\Deploy\Domain\DomainDnsVerifier;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\Shared\Infrastructure\Security\SsrfGuard;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(DomainDnsVerifier::class)]
final readonly class PublicDomainDnsVerifier implements DomainDnsVerifier
{
    public function __construct(
        private SsrfGuard $ssrfGuard,
        private ServerSettings $serverSettings,
    ) {
    }

    public function verifyPublicDns(string $domain): void
    {
        $ips = $this->resolveHost($domain);
        if ([] === $ips) {
            throw new InvalidInput(sprintf('DNS check failed: domain "%s" does not resolve to any public A/AAAA record', $domain));
        }

        foreach ($ips as $ip) {
            if (!$this->ssrfGuard->isPublicIp($ip)) {
                throw new InvalidInput(sprintf('DNS check failed: domain "%s" resolves to non-public IP "%s"', $domain, $ip));
            }
        }

        $serverHost = $this->extractServerHost();
        if (null !== $serverHost && !\in_array($serverHost, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true)) {
            $serverIps = false !== @inet_pton($serverHost) ? [$serverHost] : $this->resolveHost($serverHost);
            if ([] !== $serverIps && [] === array_intersect($ips, $serverIps)) {
                throw new InvalidInput(sprintf(
                    'DNS check failed: domain "%s" resolves to [%s], which does not match gickers.sh server IP [%s]',
                    $domain,
                    implode(', ', $ips),
                    implode(', ', $serverIps),
                ));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function resolveHost(string $host): array
    {
        $ips = [];
        $records = @dns_get_record($host, \DNS_A | \DNS_AAAA);
        if (\is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip']) && \is_string($record['ip'])) {
                    $ips[] = $record['ip'];
                }
                if (isset($record['ipv6']) && \is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ([] === $ips) {
            $byName = @gethostbynamel($host);
            if (\is_array($byName)) {
                $ips = array_merge($ips, $byName);
            }
        }

        return array_values(array_unique($ips));
    }

    private function extractServerHost(): ?string
    {
        $parts = parse_url($this->serverSettings->httpPublicUrl);
        if (false === $parts || !isset($parts['host'])) {
            return null;
        }

        return strtolower(trim($parts['host']));
    }
}

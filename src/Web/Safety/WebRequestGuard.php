<?php

declare(strict_types=1);

namespace Kosmokrator\Web\Safety;

final class WebRequestGuard
{
    public function assertSafePublicUrl(string $url): void
    {
        if (mb_strlen($url) > 2048) {
            throw new \RuntimeException('URL is too long.');
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new \RuntimeException('URL is invalid.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('Only http and https URLs are allowed.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('URLs with embedded credentials are not allowed.');
        }

        $host = strtolower((string) $parts['host']);
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new \RuntimeException('Localhost URLs are not allowed.');
        }

        $ips = $this->resolveIpAddresses($host);
        if ($ips === []) {
            throw new \RuntimeException("Could not resolve host '{$host}'.");
        }

        foreach ($ips as $ip) {
            if ($this->isPrivateOrReservedIp($ip)) {
                throw new \RuntimeException("Blocked private or reserved address for host '{$host}'.");
            }
        }
    }

    /**
     * @return list<string>
     */
    private function resolveIpAddresses(string $host): array
    {
        $ips = [];

        $v4 = gethostbynamel($host);
        if (is_array($v4)) {
            foreach ($v4 as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    $ips[] = $ip;
                }
            }
        }

        $records = dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                $ipv6 = $record['ipv6'] ?? null;
                if (is_string($ipv6) && filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $ips[] = $ipv6;
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private function isPrivateOrReservedIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}

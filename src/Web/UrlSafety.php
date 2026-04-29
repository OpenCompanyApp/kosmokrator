<?php

declare(strict_types=1);

namespace Kosmokrator\Web;

final class UrlSafety
{
    /** @var list<string> */
    private const BLOCKED_HOSTNAMES = [
        'localhost',
        'metadata.google.internal',
        'metadata.goog',
    ];

    /** @var list<string> */
    private const ALWAYS_BLOCKED_IPS = [
        '169.254.169.254',
        '169.254.170.2',
        '169.254.169.253',
        '100.100.100.200',
    ];

    public static function assertSafe(string $url): void
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            throw new WebProviderException("Invalid URL: {$url}");
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new WebProviderException('URL must use http or https.');
        }

        if (($parts['user'] ?? '') !== '' || ($parts['pass'] ?? '') !== '') {
            throw new WebProviderException('URL credentials are not allowed.');
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '') {
            throw new WebProviderException('URL host is required.');
        }

        if (in_array($host, self::BLOCKED_HOSTNAMES, true)) {
            throw new WebProviderException("Blocked internal hostname: {$host}");
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);
        foreach ($ips as $ip) {
            if (self::isBlockedIp($ip)) {
                throw new WebProviderException("Blocked private/internal address: {$host}");
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (! is_array($records) || $records === []) {
            throw new WebProviderException("DNS resolution failed for {$host}");
        }

        $ips = [];
        foreach ($records as $record) {
            foreach (['ip', 'ipv6'] as $field) {
                if (isset($record[$field]) && is_string($record[$field])) {
                    $ips[] = $record[$field];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private static function isBlockedIp(string $ip): bool
    {
        if (in_array($ip, self::ALWAYS_BLOCKED_IPS, true)) {
            return true;
        }

        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);
            if ($long === false) {
                return true;
            }

            return self::ipv4InRange($long, '100.64.0.0', 10)
                || self::ipv4InRange($long, '169.254.0.0', 16);
        }

        return str_starts_with(strtolower($ip), 'fe80:')
            || str_starts_with(strtolower($ip), 'fd00:')
            || str_starts_with(strtolower($ip), 'fc00:');
    }

    private static function ipv4InRange(int $ip, string $network, int $bits): bool
    {
        $net = ip2long($network);
        if ($net === false) {
            return false;
        }

        $mask = -1 << (32 - $bits);

        return ($ip & $mask) === ($net & $mask);
    }
}

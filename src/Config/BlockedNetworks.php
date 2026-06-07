<?php

declare(strict_types=1);

namespace LiteStrip\Config;

/**
 * SSRF protection: determines whether an IP address belongs to a private or reserved network.
 *
 * Blocks RFC 1918 private ranges, loopback, link-local (including cloud metadata endpoints),
 * shared address space (RFC 6598), documentation ranges, and benchmarking ranges.
 */
final class BlockedNetworks
{
    /** @var list<string> Blocked IPv4 CIDR ranges */
    private const array IPV4_BLOCKED = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
    ];

    /**
     * Checks whether the given IP address is in a blocked network.
     *
     * @param string $ip IPv4 or IPv6 address
     * @return bool True if the IP is blocked, false if safe to connect
     */
    public static function isBlocked(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return self::matchesIpv4CidrList($ip);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return self::isBlockedIpv6($ip);
        }
        return true;
    }

    /**
     * Checks an IPv4 address against the CIDR blocklist.
     *
     * @param string $ip IPv4 address to check
     * @return bool True if the IP matches any blocked CIDR range
     */
    private static function matchesIpv4CidrList(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 4) {
            return true;
        }
        $ipLong = unpack('N', $packed)[1];

        foreach (self::IPV4_BLOCKED as $cidr) {
            [$subnet, $bits] = explode('/', $cidr, 2);
            $subnetPacked = @inet_pton($subnet);
            if ($subnetPacked === false) {
                continue;
            }
            $subnetLong = unpack('N', $subnetPacked)[1];
            $mask = -1 << (32 - (int) $bits);
            if (($ipLong & $mask) === ($subnetLong & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks an IPv6 address against known blocked prefixes.
     * Blocks ::1 (loopback), fc00::/7 (ULA), and fe80::/10 (link-local).
     *
     * @param string $ip IPv6 address to check
     * @return bool True if the IP belongs to a blocked IPv6 range
     */
    private static function isBlockedIpv6(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return true;
        }

        $hex = bin2hex($packed);

        if ($hex === '00000000000000000000000000000001') {
            return true;
        }

        $firstByte = hexdec(substr($hex, 0, 2));
        if (($firstByte & 0xFE) === 0xFC) {
            return true;
        }

        $firstTwoBytes = hexdec(substr($hex, 0, 4));
        if (($firstTwoBytes & 0xFFC0) === 0xFE80) {
            return true;
        }

        return false;
    }
}

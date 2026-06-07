<?php

declare(strict_types=1);

namespace LiteStrip\Config;

final class BlockedNetworks
{
    /** @var list<string> CIDR notation */
    private const IPV4_BLOCKED = [
        '0.0.0.0/8',        // Current network
        '10.0.0.0/8',       // RFC 1918 Private
        '100.64.0.0/10',    // RFC 6598 Shared Address Space
        '127.0.0.0/8',      // Loopback
        '169.254.0.0/16',   // Link-local (cloud metadata 169.254.169.254)
        '172.16.0.0/12',    // RFC 1918 Private (Docker bridge)
        '192.0.0.0/24',     // IETF Protocol Assignments
        '192.0.2.0/24',     // TEST-NET-1
        '192.168.0.0/16',   // RFC 1918 Private
        '198.18.0.0/15',    // Benchmarking
        '198.51.100.0/24',  // TEST-NET-2
        '203.0.113.0/24',   // TEST-NET-3
    ];

    /** @var list<string> CIDR notation */
    private const IPV6_BLOCKED = [
        '::1/128',     // Loopback
        'fc00::/7',    // Unique Local Address
        'fe80::/10',   // Link-local
    ];

    /**
     * @return bool IP がブロック対象であれば true
     */
    public static function isBlocked(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return self::matchesCidrList($ip, self::IPV4_BLOCKED);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return self::matchesCidrList($ip, self::IPV6_BLOCKED);
        }
        return true;
    }

    private static function matchesCidrList(string $ip, array $cidrList): bool
    {
        $ipLong = self::ipToLong($ip);
        if ($ipLong === false) {
            return true;
        }

        foreach ($cidrList as $cidr) {
            [$subnet, $bits] = explode('/', $cidr, 2);
            $subnetLong = self::ipToLong($subnet);
            if ($subnetLong === false) {
                continue;
            }

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $mask = -1 << (32 - (int) $bits);
                if (($ipLong & $mask) === ($subnetLong & $mask)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function ipToLong(string $ip): int|false
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            return unpack('N', $packed)[1];
        }

        // IPv6: simplified check for well-known blocked prefixes
        $hex = bin2hex($packed);
        // ::1
        if ($hex === '00000000000000000000000000000001') {
            return 1;
        }
        // fc00::/7
        $firstByte = hexdec(substr($hex, 0, 2));
        if (($firstByte & 0xFE) === 0xFC) {
            return 1;
        }
        // fe80::/10
        $firstTwoBytes = hexdec(substr($hex, 0, 4));
        if (($firstTwoBytes & 0xFFC0) === 0xFE80) {
            return 1;
        }

        return 0;
    }

    /**
     * @return bool IPv6 がブロック対象であれば true
     */
    public static function isBlockedIpv6(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return true;
        }

        $hex = bin2hex($packed);

        // ::1
        if ($hex === '00000000000000000000000000000001') {
            return true;
        }

        $firstByte = hexdec(substr($hex, 0, 2));
        // fc00::/7
        if (($firstByte & 0xFE) === 0xFC) {
            return true;
        }

        $firstTwoBytes = hexdec(substr($hex, 0, 4));
        // fe80::/10
        if (($firstTwoBytes & 0xFFC0) === 0xFE80) {
            return true;
        }

        return false;
    }
}

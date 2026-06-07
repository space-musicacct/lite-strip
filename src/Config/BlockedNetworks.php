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

    /**
     * @return bool IP がブロック対象であれば true
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

    private static function isBlockedIpv6(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return true;
        }

        $hex = bin2hex($packed);

        // ::1/128
        if ($hex === '00000000000000000000000000000001') {
            return true;
        }

        // fc00::/7
        $firstByte = hexdec(substr($hex, 0, 2));
        if (($firstByte & 0xFE) === 0xFC) {
            return true;
        }

        // fe80::/10
        $firstTwoBytes = hexdec(substr($hex, 0, 4));
        if (($firstTwoBytes & 0xFFC0) === 0xFE80) {
            return true;
        }

        return false;
    }
}

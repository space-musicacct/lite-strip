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
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
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
     *
     * Blocks the unspecified address (::), loopback (::1), IPv4-mapped
     * (::ffff:0:0/96), IPv4-translated (::ffff:0:0:0/96), deprecated
     * IPv4-compatible (::/96), NAT64 (64:ff9b::/96) and 6to4 (2002::/16) — for
     * these the embedded IPv4 address is re-checked against the IPv4 blocklist
     * so an internal target cannot be smuggled inside an IPv6 wrapper — plus
     * Teredo (2001::/32), documentation (2001:db8::/32), ULA (fc00::/7),
     * link-local (fe80::/10) and multicast (ff00::/8).
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

        $bytes = array_values(unpack('C16', $packed));
        $hex = bin2hex($packed);

        // Unspecified (::) and loopback (::1).
        if ($hex === str_repeat('0', 32) || $hex === str_repeat('0', 31) . '1') {
            return true;
        }

        // IPv4-mapped ::ffff:a.b.c.d — check the embedded IPv4 address.
        $first10Zero = array_sum(array_slice($bytes, 0, 10)) === 0;
        if ($first10Zero && $bytes[10] === 0xFF && $bytes[11] === 0xFF) {
            return self::matchesIpv4CidrList(self::embeddedIpv4($bytes, 12));
        }

        // IPv4-translated ::ffff:0:a.b.c.d (SIIT, ::ffff:0:0:0/96) — check the embedded IPv4 address.
        $first8Zero = array_sum(array_slice($bytes, 0, 8)) === 0;
        if ($first8Zero && $bytes[8] === 0xFF && $bytes[9] === 0xFF && $bytes[10] === 0 && $bytes[11] === 0) {
            return self::matchesIpv4CidrList(self::embeddedIpv4($bytes, 12));
        }

        // Deprecated IPv4-compatible ::a.b.c.d (first 96 bits zero, non-trivial tail).
        if ($first10Zero && $bytes[10] === 0 && $bytes[11] === 0) {
            return self::matchesIpv4CidrList(self::embeddedIpv4($bytes, 12));
        }

        // NAT64 well-known prefix 64:ff9b::/96 — check the embedded IPv4 address.
        if (str_starts_with($hex, '0064ff9b') && array_sum(array_slice($bytes, 4, 8)) === 0) {
            return self::matchesIpv4CidrList(self::embeddedIpv4($bytes, 12));
        }

        // 6to4 2002::/16 — the embedded IPv4 address is at bytes 2..5.
        if ($bytes[0] === 0x20 && $bytes[1] === 0x02) {
            return self::matchesIpv4CidrList(self::embeddedIpv4($bytes, 2));
        }

        // Teredo 2001::/32.
        if ($bytes[0] === 0x20 && $bytes[1] === 0x01 && $bytes[2] === 0x00 && $bytes[3] === 0x00) {
            return true;
        }

        // Documentation 2001:db8::/32.
        if ($bytes[0] === 0x20 && $bytes[1] === 0x01 && $bytes[2] === 0x0D && $bytes[3] === 0xB8) {
            return true;
        }

        // ULA fc00::/7.
        if (($bytes[0] & 0xFE) === 0xFC) {
            return true;
        }

        // Link-local fe80::/10.
        if (($bytes[0] === 0xFE) && (($bytes[1] & 0xC0) === 0x80)) {
            return true;
        }

        // Multicast ff00::/8.
        if ($bytes[0] === 0xFF) {
            return true;
        }

        return false;
    }

    /**
     * Reads a dotted-quad IPv4 address embedded at the given byte offset of an IPv6 address.
     *
     * @param list<int> $bytes 16 unsigned bytes of the IPv6 address
     * @param int $offset Byte offset of the embedded IPv4 address
     * @return string Dotted-quad IPv4 address
     */
    private static function embeddedIpv4(array $bytes, int $offset): string
    {
        return sprintf('%d.%d.%d.%d', $bytes[$offset], $bytes[$offset + 1], $bytes[$offset + 2], $bytes[$offset + 3]);
    }
}

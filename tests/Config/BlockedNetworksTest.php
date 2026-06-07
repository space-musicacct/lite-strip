<?php

declare(strict_types=1);

namespace LiteStrip\Tests\Config;

use LiteStrip\Config\BlockedNetworks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BlockedNetworksTest extends TestCase
{
    #[DataProvider('blockedIpv4Provider')]
    public function testBlocksPrivateIpv4(string $ip): void
    {
        $this->assertTrue(BlockedNetworks::isBlocked($ip), "Expected $ip to be blocked");
    }

    public static function blockedIpv4Provider(): array
    {
        return [
            'loopback 127.0.0.1' => ['127.0.0.1'],
            'loopback 127.255.255.255' => ['127.255.255.255'],
            'class A private 10.0.0.1' => ['10.0.0.1'],
            'class A private 10.255.255.255' => ['10.255.255.255'],
            'class B private 172.16.0.1' => ['172.16.0.1'],
            'class B private 172.31.255.255' => ['172.31.255.255'],
            'class C private 192.168.0.1' => ['192.168.0.1'],
            'class C private 192.168.255.255' => ['192.168.255.255'],
            'link-local 169.254.0.1' => ['169.254.0.1'],
            'cloud metadata 169.254.169.254' => ['169.254.169.254'],
            'current network 0.0.0.0' => ['0.0.0.0'],
            'current network 0.255.255.255' => ['0.255.255.255'],
            'shared address space 100.64.0.1' => ['100.64.0.1'],
            'shared address space 100.127.255.255' => ['100.127.255.255'],
            'IETF protocol 192.0.0.1' => ['192.0.0.1'],
            'TEST-NET-1 192.0.2.1' => ['192.0.2.1'],
            'benchmarking 198.18.0.1' => ['198.18.0.1'],
            'TEST-NET-2 198.51.100.1' => ['198.51.100.1'],
            'TEST-NET-3 203.0.113.1' => ['203.0.113.1'],
        ];
    }

    #[DataProvider('allowedIpv4Provider')]
    public function testAllowsPublicIpv4(string $ip): void
    {
        $this->assertFalse(BlockedNetworks::isBlocked($ip), "Expected $ip to be allowed");
    }

    public static function allowedIpv4Provider(): array
    {
        return [
            'Google DNS 8.8.8.8' => ['8.8.8.8'],
            'Cloudflare DNS 1.1.1.1' => ['1.1.1.1'],
            'public 93.184.216.34' => ['93.184.216.34'],
            'just outside class B 172.32.0.1' => ['172.32.0.1'],
            'just outside shared 100.128.0.1' => ['100.128.0.1'],
        ];
    }

    #[DataProvider('blockedIpv6Provider')]
    public function testBlocksPrivateIpv6(string $ip): void
    {
        $this->assertTrue(BlockedNetworks::isBlocked($ip), "Expected $ip to be blocked");
    }

    public static function blockedIpv6Provider(): array
    {
        return [
            'loopback ::1' => ['::1'],
            'ULA fd00::1' => ['fd00::1'],
            'ULA fc00::1' => ['fc00::1'],
            'link-local fe80::1' => ['fe80::1'],
            'link-local fe80::abcd:1234' => ['fe80::abcd:1234'],
        ];
    }

    #[DataProvider('allowedIpv6Provider')]
    public function testAllowsPublicIpv6(string $ip): void
    {
        $this->assertFalse(BlockedNetworks::isBlocked($ip), "Expected $ip to be allowed");
    }

    public static function allowedIpv6Provider(): array
    {
        return [
            'Google DNS 2001:4860:4860::8888' => ['2001:4860:4860::8888'],
            'Cloudflare 2606:4700:4700::1111' => ['2606:4700:4700::1111'],
        ];
    }

    public function testBlocksInvalidInput(): void
    {
        $this->assertTrue(BlockedNetworks::isBlocked('not-an-ip'));
        $this->assertTrue(BlockedNetworks::isBlocked(''));
    }
}

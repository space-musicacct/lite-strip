<?php

declare(strict_types=1);

namespace LiteStrip\Fetcher;

use LiteStrip\Config\BlockedNetworks;
use LiteStrip\Config\ServerConfig;
use React\Dns\Model\Message;
use React\Dns\Resolver\ResolverInterface;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Socket\ConnectorInterface;
use RuntimeException;
use Throwable;

use function React\Promise\reject;

/**
 * SSRF-safe socket connector.
 *
 * This is the single choke point for every outbound connection the fetcher makes.
 * It resolves the destination host itself, discards any IP that belongs to a
 * blocked network, and then connects to one of the *vetted* IPs directly — so the
 * address that was checked is exactly the address that is dialed. That closes the
 * time-of-check/time-of-use gap between {@see UrlValidator} (which resolves via its
 * own resolver) and the HTTP client's default connector (which would resolve again,
 * independently, and try every A/AAAA record via Happy Eyeballs).
 *
 * Covers the main page fetch, manual redirects, discovered API endpoints and
 * external JS fetches, because they all share the one {@see \React\Http\Browser}
 * built on top of this connector. The SPA path (headless Chromium) does its own
 * resolution and is out of scope here.
 *
 * Only IPv4 (A records) is resolved: the deployment has no IPv6 egress, and
 * refusing AAAA avoids the IPv4-mapped/NAT64 embedding tricks entirely.
 */
final class SafeConnector implements ConnectorInterface
{
    /** @var ConnectorInterface Base connector with DNS disabled — it only ever dials literal IPs we vetted. */
    private ConnectorInterface $base;

    /**
     * @param ResolverInterface $resolver Resolver used to enumerate a host's A records
     * @param ConnectorInterface|null $base Base connector; defaults to a DNS-less TCP/TLS connector
     */
    public function __construct(
        private readonly ResolverInterface $resolver,
        ?ConnectorInterface $base = null,
    ) {
        // dns=false: the base must never resolve — we hand it literal IPs only.
        // happy_eyeballs=false: no independent multi-address racing behind our back.
        $this->base = $base ?? new Connector([
            'dns' => false,
            'happy_eyeballs' => false,
            'timeout' => ServerConfig::DEFAULT_TIMEOUT,
        ]);
    }

    /**
     * Validates the destination IP(s) before dialing and connects only to a vetted address.
     *
     * @param string $uri Connection URI, e.g. tcp://host:80 or tls://host:443?hostname=...
     */
    public function connect($uri)
    {
        $parts = parse_url($uri);
        if ($parts === false || !isset($parts['host'])) {
            return reject(new RuntimeException('Invalid connection URI'));
        }

        $scheme = $parts['scheme'] ?? 'tcp';
        $host = (string) $parts['host'];
        // parse_url keeps IPv6 literals bracketed (e.g. "[::1]").
        $hostname = trim($host, '[]');
        $port = $parts['port'] ?? ($scheme === 'tls' ? 443 : 80);

        parse_str($parts['query'] ?? '', $query);

        // Literal IP: check it directly, then pass the original URI straight through.
        if (filter_var($hostname, FILTER_VALIDATE_IP)) {
            if (BlockedNetworks::isBlocked($hostname)) {
                return reject(new RuntimeException('The requested URL resolves to a private network address'));
            }
            return $this->base->connect($uri);
        }

        // Hostname: enumerate A records, drop blocked ones, dial only what survived.
        return $this->resolver->resolveAll($hostname, Message::TYPE_A)->then(
            function (array $ips) use ($scheme, $port, $hostname, $query) {
                $safe = array_values(array_filter(
                    $ips,
                    static fn (string $ip): bool => !BlockedNetworks::isBlocked($ip),
                ));

                if ($safe === []) {
                    throw new RuntimeException('The requested URL resolves to a private network address');
                }

                return $this->dial($safe, 0, $scheme, (int) $port, $hostname, $query);
            },
            static function (Throwable $e): never {
                throw new RuntimeException('DNS resolution failed: ' . $e->getMessage(), 0, $e);
            },
        );
    }

    /**
     * Dials vetted IPs in order, falling back to the next on failure.
     *
     * @param list<string> $ips Vetted (non-blocked) IP addresses
     * @param int $index Current attempt index
     * @param string $scheme URI scheme (tcp/tls)
     * @param int $port Destination port
     * @param string $hostname Original hostname, preserved for TLS SNI / certificate verification
     * @param array<string, string> $query Extra query parameters from the original URI
     * @return PromiseInterface<ConnectionInterface>
     */
    private function dial(array $ips, int $index, string $scheme, int $port, string $hostname, array $query): PromiseInterface
    {
        $ip = $ips[$index];
        $ipHost = str_contains($ip, ':') ? "[$ip]" : $ip;
        $query['hostname'] = $hostname; // keep the real hostname for SNI + cert peer name
        $uri = $scheme . '://' . $ipHost . ':' . $port . '?' . http_build_query($query);

        return $this->base->connect($uri)->then(null, function (Throwable $e) use ($ips, $index, $scheme, $port, $hostname, $query): PromiseInterface {
            if (isset($ips[$index + 1])) {
                return $this->dial($ips, $index + 1, $scheme, $port, $hostname, $query);
            }
            throw $e;
        });
    }
}

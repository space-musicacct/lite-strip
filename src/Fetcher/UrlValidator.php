<?php

declare(strict_types=1);

namespace LiteStrip\Fetcher;

use LiteStrip\Config\BlockedNetworks;
use LiteStrip\Config\ServerConfig;
use React\Dns\Resolver\ResolverInterface;

use function React\Async\await;

class UrlValidator
{
    public function __construct(
        private readonly ResolverInterface $dnsResolver
    ) {}

    /**
     * @throws \InvalidArgumentException URL が不正な場合
     * @throws \RuntimeException         SSRF ブロック時
     */
    public function validate(string $url): void
    {
        if (strlen($url) > ServerConfig::MAX_URL_LENGTH) {
            throw new \InvalidArgumentException('URL exceeds maximum length of ' . ServerConfig::MAX_URL_LENGTH);
        }

        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            throw new \InvalidArgumentException('Invalid URL format');
        }

        $scheme = strtolower($parsed['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \InvalidArgumentException('Only http and https schemes are supported');
        }

        if (isset($parsed['user']) || isset($parsed['pass'])) {
            throw new \InvalidArgumentException('URLs with credentials are not allowed');
        }

        $this->validateHost($parsed['host']);
    }

    /**
     * @throws \RuntimeException SSRF ブロック時
     */
    public function validateHost(string $host): void
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (BlockedNetworks::isBlocked($host)) {
                throw new \RuntimeException('The requested URL resolves to a private network address');
            }
            return;
        }

        try {
            /** @var string|null $ip */
            $ip = await($this->dnsResolver->resolve($host));
        } catch (\Exception $e) {
            throw new \RuntimeException('DNS resolution failed for host: ' . $host);
        }

        if ($ip === null || !is_string($ip)) {
            throw new \RuntimeException('DNS resolution returned no result for host: ' . $host);
        }

        if (BlockedNetworks::isBlocked($ip)) {
            throw new \RuntimeException('The requested URL resolves to a private network address');
        }
    }

    /**
     * @return bool same-origin 判定
     */
    public function isSameOrigin(string $baseUrl, string $targetUrl): bool
    {
        $base = parse_url($baseUrl);
        $target = parse_url($targetUrl);

        if (!$base || !$target) {
            return false;
        }

        $baseScheme = strtolower($base['scheme'] ?? '');
        $targetScheme = strtolower($target['scheme'] ?? '');
        $baseHost = strtolower($base['host'] ?? '');
        $targetHost = strtolower($target['host'] ?? '');
        $basePort = $base['port'] ?? ($baseScheme === 'https' ? 443 : 80);
        $targetPort = $target['port'] ?? ($targetScheme === 'https' ? 443 : 80);

        return $baseScheme === $targetScheme
            && $baseHost === $targetHost
            && $basePort === $targetPort;
    }

    /**
     * @return string 相対 URL を絶対 URL に解決
     */
    public function resolveUrl(string $baseUrl, string $relativeUrl): string
    {
        if (preg_match('#^https?://#i', $relativeUrl)) {
            return $relativeUrl;
        }

        $parsed = parse_url($baseUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        if (str_starts_with($relativeUrl, '//')) {
            return $scheme . ':' . $relativeUrl;
        }

        $origin = $scheme . '://' . $host . $port;

        if (str_starts_with($relativeUrl, '/')) {
            return $origin . $relativeUrl;
        }

        $basePath = $parsed['path'] ?? '/';
        $baseDir = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);

        return $origin . $baseDir . $relativeUrl;
    }
}

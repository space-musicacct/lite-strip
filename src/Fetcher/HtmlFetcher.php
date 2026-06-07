<?php

declare(strict_types=1);

namespace LiteStrip\Fetcher;

use LiteStrip\Config\ServerConfig;
use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;
use RuntimeException;
use Throwable;

use function React\Async\await;

/**
 * Fetches HTML content from URLs using ReactPHP's non-blocking HTTP client.
 *
 * Handles redirects manually to allow per-hop SSRF validation,
 * and enforces response size limits.
 */
class HtmlFetcher
{
    /** @var Browser ReactPHP HTTP client configured with timeout, redirect, and header defaults */
    private Browser $browser;

    /**
     * Creates a new HTML fetcher with preconfigured browser settings.
     *
     * @param Browser $browser ReactPHP HTTP browser instance to configure and use for requests
     * @param UrlValidator|null $urlValidator URL validator for SSRF protection on redirects
     */
    public function __construct(Browser $browser, private readonly ?UrlValidator $urlValidator = null)
    {
        $this->browser = $browser
            ->withTimeout(ServerConfig::DEFAULT_TIMEOUT)
            ->withFollowRedirects(false)
            ->withHeader('User-Agent', ServerConfig::USER_AGENT)
            ->withHeader('Accept', 'text/html, application/xhtml+xml, */*');
    }

    /**
     * Fetches an HTML page, following redirects manually up to the configured limit.
     *
     * @param string $url Target URL to fetch
     * @param int $timeout Per-request timeout in seconds
     * @return array{html: string, finalUrl: string, status: int, contentType: string}
     * @throws RuntimeException If fetch fails, too many redirects, or response too large
     * @throws Throwable On unexpected HTTP client errors
     */
    public function fetch(string $url, int $timeout = ServerConfig::DEFAULT_TIMEOUT): array
    {
        $browser = $this->browser->withTimeout($timeout);
        $finalUrl = $url;
        $redirectCount = 0;

        while (true) {
            /** @var ResponseInterface $response */
            $response = await($browser->get($finalUrl));
            $status = $response->getStatusCode();

            if ($status >= 300 && $status < 400) {
                $redirectCount++;
                if ($redirectCount > ServerConfig::MAX_REDIRECTS) {
                    throw new RuntimeException('Too many redirects');
                }

                $location = $response->getHeaderLine('Location');
                if ($location === '') {
                    throw new RuntimeException('Redirect without Location header');
                }

                $finalUrl = $this->resolveRedirect($finalUrl, $location);
                $this->urlValidator?->validate($finalUrl);
                continue;
            }

            $body = (string) $response->getBody();

            if (strlen($body) > ServerConfig::MAX_HTML_SIZE) {
                throw new RuntimeException('Response exceeds maximum size of ' . ServerConfig::MAX_HTML_SIZE . ' bytes');
            }

            return [
                'html' => $body,
                'finalUrl' => $finalUrl,
                'status' => $status,
                'contentType' => $response->getHeaderLine('Content-Type'),
            ];
        }
    }

    /**
     * Fetches a URL and returns the response body as a string.
     *
     * @param string $url Target URL
     * @param int $timeout Timeout in seconds
     * @return string Response body
     * @throws Throwable On fetch failure or size limit exceeded
     */
    public function fetchText(string $url, int $timeout = ServerConfig::API_ENDPOINT_TIMEOUT): string
    {
        return $this->fetchWithStatus($url, $timeout)['body'];
    }

    /**
     * Fetches a URL and returns the response with status code and headers.
     *
     * @param string $url Target URL
     * @param int $timeout Timeout in seconds
     * @return array{body: string, status: int, reasonPhrase: string, contentType: string}
     * @throws Throwable On fetch failure or size limit exceeded
     */
    public function fetchWithStatus(string $url, int $timeout = ServerConfig::API_ENDPOINT_TIMEOUT): array
    {
        $browser = $this->browser->withTimeout($timeout);

        /** @var ResponseInterface $response */
        $response = await($browser->get($url));
        $body = (string) $response->getBody();

        if (strlen($body) > ServerConfig::MAX_API_RESPONSE_SIZE) {
            throw new RuntimeException('API response exceeds maximum size');
        }

        return [
            'body' => $body,
            'status' => $response->getStatusCode(),
            'reasonPhrase' => $response->getReasonPhrase(),
            'contentType' => $response->getHeaderLine('Content-Type'),
        ];
    }

    /**
     * Resolves a redirect Location header against the current URL.
     *
     * @param string $baseUrl The URL that issued the redirect
     * @param string $location The Location header value (absolute or relative)
     * @return string The resolved absolute URL
     */
    private function resolveRedirect(string $baseUrl, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parsed = parse_url($baseUrl);
        $origin = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
        if (isset($parsed['port'])) {
            $origin .= ':' . $parsed['port'];
        }

        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $basePath = $parsed['path'] ?? '/';
        $baseDir = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);
        return $origin . $baseDir . $location;
    }
}

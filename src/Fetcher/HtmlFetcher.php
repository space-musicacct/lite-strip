<?php

declare(strict_types=1);

namespace LiteStrip\Fetcher;

use LiteStrip\Config\ServerConfig;
use React\Http\Browser;
use Psr\Http\Message\ResponseInterface;

use function React\Async\await;

class HtmlFetcher
{
    private Browser $browser;

    public function __construct(Browser $browser)
    {
        $this->browser = $browser
            ->withTimeout(ServerConfig::DEFAULT_TIMEOUT)
            ->withFollowRedirects(false)
            ->withHeader('User-Agent', ServerConfig::USER_AGENT)
            ->withHeader('Accept', 'text/html, application/xhtml+xml, */*');
    }

    /**
     * @param string $url     取得対象 URL
     * @param int    $timeout タイムアウト秒数
     * @return array{html: string, finalUrl: string, status: int, contentType: string}
     * @throws \RuntimeException 取得失敗時
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
                    throw new \RuntimeException('Too many redirects');
                }

                $location = $response->getHeaderLine('Location');
                if ($location === '') {
                    throw new \RuntimeException('Redirect without Location header');
                }

                $finalUrl = $this->resolveRedirect($finalUrl, $location);
                continue;
            }

            $body = (string) $response->getBody();

            if (strlen($body) > ServerConfig::MAX_HTML_SIZE) {
                throw new \RuntimeException('Response exceeds maximum size of ' . ServerConfig::MAX_HTML_SIZE . ' bytes');
            }

            $contentType = $response->getHeaderLine('Content-Type');

            return [
                'html' => $body,
                'finalUrl' => $finalUrl,
                'status' => $status,
                'contentType' => $contentType,
            ];
        }
    }

    /**
     * @param string $url 取得対象 URL
     * @return string レスポンスボディ
     */
    public function fetchText(string $url, int $timeout = ServerConfig::API_ENDPOINT_TIMEOUT): string
    {
        return $this->fetchWithStatus($url, $timeout)['body'];
    }

    /**
     * @return array{body: string, status: int, reasonPhrase: string, contentType: string}
     */
    public function fetchWithStatus(string $url, int $timeout = ServerConfig::API_ENDPOINT_TIMEOUT): array
    {
        $browser = $this->browser->withTimeout($timeout);

        /** @var ResponseInterface $response */
        $response = await($browser->get($url));
        $body = (string) $response->getBody();

        if (strlen($body) > ServerConfig::MAX_API_RESPONSE_SIZE) {
            throw new \RuntimeException('API response exceeds maximum size');
        }

        return [
            'body' => $body,
            'status' => $response->getStatusCode(),
            'reasonPhrase' => $response->getReasonPhrase(),
            'contentType' => $response->getHeaderLine('Content-Type'),
        ];
    }

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

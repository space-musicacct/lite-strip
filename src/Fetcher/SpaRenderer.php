<?php

declare(strict_types=1);

namespace LiteStrip\Fetcher;

use HeadlessChromium\Browser;
use HeadlessChromium\Browser\BrowserProcess;
use HeadlessChromium\Communication\Connection;

class SpaRenderer
{
    private string $chromiumHost;
    private int $chromiumPort;

    public function __construct(
        string $chromiumHost = 'chromium',
        int $chromiumPort = 9222
    ) {
        $this->chromiumHost = $chromiumHost;
        $this->chromiumPort = $chromiumPort;
    }

    /**
     * @param string $url     レンダリング対象 URL
     * @param int    $timeout タイムアウト秒数
     * @return array{html: string, finalUrl: string, status: int}
     */
    public function render(string $url, int $timeout = 15): array
    {
        $wsEndpoint = $this->discoverWebSocketEndpoint();

        $connection = new Connection($wsEndpoint);
        $connection->connect();

        $browser = new Browser($connection);

        try {
            $page = $browser->createPage();
            $page->navigate($url)->waitForNavigation('networkIdle', $timeout * 1000);

            $html = $page->evaluate('document.documentElement.outerHTML')->getReturnValue();
            $finalUrl = $page->evaluate('window.location.href')->getReturnValue();

            $page->close();

            return [
                'html' => $html ?? '',
                'finalUrl' => $finalUrl ?? $url,
                'status' => 200,
            ];
        } catch (\Throwable $e) {
            throw new \RuntimeException('SPA rendering failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $connection->disconnect();
        }
    }

    private function discoverWebSocketEndpoint(): string
    {
        // Chrome は Host ヘッダが IP or localhost でないと拒否するため、
        // hostname を IP に解決してから全通信を IP ベースで行う
        $ip = gethostbyname($this->chromiumHost);

        $json = @file_get_contents(
            "http://{$ip}:{$this->chromiumPort}/json/version",
            false,
            stream_context_create(['http' => ['timeout' => 5]])
        );

        if ($json === false) {
            throw new \RuntimeException("Cannot connect to Chromium at {$this->chromiumHost}:{$this->chromiumPort}");
        }

        $data = json_decode($json, true);
        if (!isset($data['webSocketDebuggerUrl'])) {
            throw new \RuntimeException('Chromium did not return webSocketDebuggerUrl');
        }

        $wsUrl = $data['webSocketDebuggerUrl'];
        $path = parse_url($wsUrl, PHP_URL_PATH);

        return "ws://{$ip}:{$this->chromiumPort}{$path}";
    }
}

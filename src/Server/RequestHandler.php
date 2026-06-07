<?php

declare(strict_types=1);

namespace LiteStrip\Server;

use LiteStrip\Config\ServerConfig;
use LiteStrip\Fetcher\HtmlFetcher;
use LiteStrip\Fetcher\SpaRenderer;
use LiteStrip\Fetcher\UrlValidator;
use LiteStrip\Follower\ApiFollower;
use LiteStrip\Formatter\HtmlFormatter;
use LiteStrip\Formatter\JsonFormatter;
use LiteStrip\Formatter\MarkdownFormatter;
use LiteStrip\Processor\ContentExtractor;
use LiteStrip\Processor\DomProcessor;
use LiteStrip\Processor\ScriptAnalyzer;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

class RequestHandler
{
    private bool $spaLock = false;

    public function __construct(
        private readonly UrlValidator $urlValidator,
        private readonly HtmlFetcher $htmlFetcher,
        private readonly ?SpaRenderer $spaRenderer,
        private readonly ScriptAnalyzer $scriptAnalyzer,
        private readonly ApiFollower $apiFollower,
        private readonly ContentExtractor $contentExtractor,
        private readonly DomProcessor $domProcessor,
        private readonly HtmlFormatter $htmlFormatter,
        private readonly JsonFormatter $jsonFormatter,
        private readonly MarkdownFormatter $markdownFormatter,
    ) {}

    public function handle(ServerRequestInterface $request): Response
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        if ($method === 'OPTIONS') {
            return $this->cors(new Response(204));
        }

        if ($path === '/health') {
            return $this->cors($this->health());
        }

        if ($path === '/docs') {
            return $this->docs();
        }

        if ($path !== '/' && $path !== '') {
            return $this->cors($this->errorResponse(404, 'NOT_FOUND', 'Endpoint not found'));
        }

        try {
            $options = $this->parseOptions($request);
        } catch (\InvalidArgumentException $e) {
            return $this->cors($this->errorResponse(400, 'INVALID_PARAMETER', $e->getMessage()));
        }

        return $this->cors($this->processUrl($options));
    }

    private function processUrl(array $options): Response
    {
        $url = $options['url'];
        $format = $options['format'];
        $followApis = $options['followApis'];
        $isFull = $options['isFull'];
        $isSpa = $options['isSpa'];
        $timeout = $options['timeout'];
        $maxApis = $options['maxApis'];

        // SPA ロック (子プロセスの Fiber 競合防止のため、同時に 1 リクエストのみ)
        if ($isSpa) {
            if ($this->spaLock) {
                return $this->errorResponse(503, 'SPA_BUSY', 'SPA renderer is processing another request. Try again later.');
            }
            $this->spaLock = true;
        }

        try {
            return $this->doProcess($url, $format, $followApis, $isFull, $isSpa, $timeout, $maxApis);
        } finally {
            if ($isSpa) {
                $this->spaLock = false;
            }
        }
    }

    private function doProcess(string $url, string $format, bool $followApis, bool $isFull, bool $isSpa, int $timeout, int $maxApis): Response
    {
        $startTime = hrtime(true);

        // 1. URL 検証
        try {
            $this->urlValidator->validate($url);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse(400, 'INVALID_URL', $e->getMessage());
        } catch (\RuntimeException $e) {
            return $this->errorResponse(403, 'BLOCKED_URL', $e->getMessage());
        }

        // 2. HTML 取得
        try {
            if ($isSpa && $this->spaRenderer) {
                $fetchResult = $this->spaRenderer->render($url, $timeout);
                $fetchResult['contentType'] = 'text/html';
            } else {
                $fetchResult = $this->htmlFetcher->fetch($url, $timeout);
            }
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            if (str_contains(strtolower($msg), 'timeout') || str_contains(strtolower($msg), 'timed out')) {
                return $this->errorResponse(504, 'TIMEOUT', 'Upstream request timed out');
            }
            if (str_contains(strtolower($msg), 'maximum size')) {
                return $this->errorResponse(502, 'RESPONSE_TOO_LARGE', $msg);
            }
            return $this->errorResponse(502, 'FETCH_FAILED', $msg);
        }

        $html = $fetchResult['html'];
        $finalUrl = $fetchResult['finalUrl'];
        $originalSize = strlen($html);
        $fetchTimeMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        // 3. Script 解析 + API 追従
        $apiResult = ['apiData' => [], 'failedApis' => [], 'detectedApis' => []];
        if ($followApis) {
            $endpoints = $this->scriptAnalyzer->extractFromHtml($html);

            // same-origin 外部 JS も解析
            $scriptSources = $this->scriptAnalyzer->extractScriptSources($html);
            foreach ($scriptSources as $src) {
                $resolvedSrc = $this->urlValidator->resolveUrl($finalUrl, $src);
                if (!$this->urlValidator->isSameOrigin($finalUrl, $resolvedSrc)) {
                    continue;
                }
                try {
                    $this->urlValidator->validate($resolvedSrc);
                    $jsCode = $this->htmlFetcher->fetchText($resolvedSrc);
                    $found = $this->scriptAnalyzer->extractFromCode($jsCode);
                    $endpoints = array_merge($endpoints, $found);
                } catch (\Throwable $e) {
                    error_log('[LiteStrip] External JS fetch failed: ' . $resolvedSrc . ' — ' . $e->getMessage());
                }
            }

            $endpoints = array_values(array_unique($endpoints));

            if (!empty($endpoints)) {
                $apiResult = $this->apiFollower->follow($finalUrl, $endpoints, $maxApis);
            }
        }

        // 4. コンテンツ抽出
        $processedHtml = $this->contentExtractor->extract($html, $isFull);

        // 5. DOM 処理 (属性剥がし)
        $cleanHtml = $this->domProcessor->process($processedHtml);

        // 6. メタデータ抽出
        $title = $this->extractTitle($html);
        $meta = $this->extractMeta($html);

        $processTimeMs = (int) ((hrtime(true) - $startTime) / 1_000_000) - $fetchTimeMs;
        $totalTimeMs = $fetchTimeMs + $processTimeMs;

        // 7. 出力整形
        $commonHeaders = [
            'X-LiteStrip-Version' => ServerConfig::VERSION,
            'X-LiteStrip-Fetch-Time' => (string) $fetchTimeMs,
            'X-LiteStrip-Process-Time' => (string) $processTimeMs,
            'X-LiteStrip-APIs-Found' => (string) count($apiResult['detectedApis']),
            'X-LiteStrip-APIs-Followed' => (string) (count($apiResult['apiData']) + count($apiResult['failedApis'])),
            'X-LiteStrip-Original-Size' => (string) $originalSize,
        ];

        switch ($format) {
            case 'json':
                $jsonData = [
                    'url' => $url,
                    'finalUrl' => $finalUrl,
                    'title' => $title,
                    'contentHtml' => $cleanHtml,
                    'contentText' => strip_tags($cleanHtml),
                    'meta' => $meta,
                    'detectedApis' => $apiResult['detectedApis'],
                    'apiData' => $apiResult['apiData'],
                    'failedApis' => $apiResult['failedApis'],
                    'warnings' => [],
                    'stats' => [
                        'fetchedAt' => date('c'),
                        'fetchTimeMs' => $fetchTimeMs,
                        'processTimeMs' => $processTimeMs,
                        'totalTimeMs' => $totalTimeMs,
                        'apisDiscovered' => count($apiResult['detectedApis']),
                        'apisFollowed' => count($apiResult['apiData']) + count($apiResult['failedApis']),
                        'apisSucceeded' => count($apiResult['apiData']),
                        'originalSizeBytes' => $originalSize,
                        'outputSizeBytes' => 0,
                    ],
                ];
                $body = $this->jsonFormatter->format($jsonData);
                $jsonData['stats']['outputSizeBytes'] = strlen($body);
                $body = $this->jsonFormatter->format($jsonData);

                $commonHeaders['X-LiteStrip-Output-Size'] = (string) strlen($body);
                return new Response(200, array_merge($commonHeaders, [
                    'Content-Type' => 'application/json; charset=utf-8',
                ]), $body);

            case 'markdown':
                $body = $this->markdownFormatter->format($cleanHtml, $apiResult['apiData']);
                $commonHeaders['X-LiteStrip-Output-Size'] = (string) strlen($body);
                return new Response(200, array_merge($commonHeaders, [
                    'Content-Type' => 'text/markdown; charset=utf-8',
                ]), $body);

            default: // html
                $body = $this->htmlFormatter->format($url, $cleanHtml, $apiResult['apiData'], $apiResult['failedApis']);
                $commonHeaders['X-LiteStrip-Output-Size'] = (string) strlen($body);
                return new Response(200, array_merge($commonHeaders, [
                    'Content-Type' => 'text/html; charset=utf-8',
                    'Content-Security-Policy' => "default-src 'none'; img-src 'none'; media-src 'none'; frame-src 'none'; script-src 'none'; style-src 'none'; base-uri 'none'; form-action 'none'",
                ]), $body);
        }
    }

    /**
     * @return array{url: string, format: string, followApis: bool, isFull: bool, isSpa: bool, timeout: int, maxApis: int}
     */
    private function parseOptions(ServerRequestInterface $request): array
    {
        $method = $request->getMethod();

        if ($method === 'POST') {
            $contentType = $request->getHeaderLine('Content-Type');
            if (!str_contains($contentType, 'application/json')) {
                throw new \InvalidArgumentException('POST requests must use Content-Type: application/json');
            }
            $body = json_decode((string) $request->getBody(), true);
            if (!is_array($body)) {
                throw new \InvalidArgumentException('Invalid JSON body');
            }
            $params = $body;
        } else {
            $params = $request->getQueryParams();
        }

        $url = $params['url'] ?? '';
        if ($url === '') {
            throw new \InvalidArgumentException('Missing required parameter: url');
        }

        $format = $params['format'] ?? ServerConfig::DEFAULT_FORMAT;
        if (!in_array($format, ServerConfig::ALLOWED_FORMATS, true)) {
            throw new \InvalidArgumentException('Invalid format. Allowed: ' . implode(', ', ServerConfig::ALLOWED_FORMATS));
        }

        $followApis = filter_var($params['follow_apis'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $isFull = filter_var($params['is_full'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $isSpa = filter_var($params['is_spa'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $timeout = (int) ($params['timeout'] ?? ServerConfig::DEFAULT_TIMEOUT);
        if ($timeout < 1 || $timeout > ServerConfig::MAX_TIMEOUT) {
            throw new \InvalidArgumentException('timeout must be between 1 and ' . ServerConfig::MAX_TIMEOUT);
        }

        $maxApis = (int) ($params['max_apis'] ?? ServerConfig::DEFAULT_MAX_APIS);
        if ($maxApis < 1 || $maxApis > ServerConfig::MAX_MAX_APIS) {
            throw new \InvalidArgumentException('max_apis must be between 1 and ' . ServerConfig::MAX_MAX_APIS);
        }

        return compact('url', 'format', 'followApis', 'isFull', 'isSpa', 'timeout', 'maxApis');
    }

    private function health(): Response
    {
        return new Response(200, [
            'Content-Type' => 'application/json; charset=utf-8',
        ], json_encode(['status' => 'ok', 'version' => ServerConfig::VERSION]));
    }

    private function docs(): Response
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>LiteStrip API</title></head>
<body>
<h1>LiteStrip API</h1>
<p>Lightweight AI-optimized HTML extraction tool.</p>
<h2>Usage</h2>
<pre>GET /?url=https://example.com&amp;format=html
GET /?url=https://example.com&amp;format=json
GET /?url=https://example.com&amp;format=markdown</pre>
<h2>Parameters</h2>
<ul>
<li><code>url</code> (required) — Target URL</li>
<li><code>format</code> — html (default), json, markdown</li>
<li><code>follow_apis</code> — true (default), false</li>
<li><code>is_full</code> — false (default), true (include head metadata)</li>
<li><code>is_spa</code> — false (default), true (render via headless Chromium)</li>
<li><code>timeout</code> — 1-30 (default: 15)</li>
<li><code>max_apis</code> — 1-10 (default: 5)</li>
</ul>
<h2>Links</h2>
<p><a href="https://github.com/space-musicacct/lite-strip">GitHub</a></p>
</body>
</html>
HTML;
        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    private function errorResponse(int $status, string $code, string $message): Response
    {
        return new Response($status, [
            'Content-Type' => 'application/json; charset=utf-8',
        ], $this->jsonFormatter->formatError($code, $message));
    }

    private function cors(Response $response): Response
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    private function extractTitle(string $html): string
    {
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        }
        return '';
    }

    private function extractMeta(string $html): array
    {
        $meta = ['description' => null, 'language' => null, 'ogImage' => null];

        if (preg_match('/<meta\s[^>]*name=["\']description["\']\s[^>]*content=["\']([^"\']+)["\']/i', $html, $m)) {
            $meta['description'] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }
        if (preg_match('/<html[^>]*\slang=["\']([^"\']+)["\']/i', $html, $m)) {
            $meta['language'] = $m[1];
        }
        if (preg_match('/<meta\s[^>]*property=["\']og:image["\']\s[^>]*content=["\']([^"\']+)["\']/i', $html, $m)) {
            $meta['ogImage'] = $m[1];
        }

        return $meta;
    }
}

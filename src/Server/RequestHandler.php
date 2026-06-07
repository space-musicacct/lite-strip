<?php

declare(strict_types=1);

namespace LiteStrip\Server;

use DateInvalidTimeZoneException;
use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;
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
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use RuntimeException;
use Throwable;

/**
 * Main HTTP request handler for LiteStrip.
 *
 * Routes incoming requests to the appropriate processing pipeline:
 * URL validation → HTML fetch (or SPA render) → script analysis →
 * API following → content extraction → DOM processing → output formatting.
 */
readonly class RequestHandler
{
    /**
     * Creates a new request handler with all required processing components.
     *
     * @param UrlValidator $urlValidator Validates URLs for safety and resolves relative URLs
     * @param HtmlFetcher $htmlFetcher Fetches HTML content from remote URLs
     * @param SpaRenderer|null $spaRenderer Renders JavaScript-heavy pages via headless Chromium, or null if SPA mode is disabled
     * @param ScriptAnalyzer $scriptAnalyzer Detects API endpoint URLs in JavaScript code
     * @param ApiFollower $apiFollower Follows and fetches discovered API endpoints
     * @param ContentExtractor $contentExtractor Extracts the relevant portion of an HTML document
     * @param DomProcessor $domProcessor Strips non-semantic attributes and removes noise elements
     * @param HtmlFormatter $htmlFormatter Formats extraction results as clean HTML
     * @param JsonFormatter $jsonFormatter Formats extraction results as structured JSON
     * @param MarkdownFormatter $markdownFormatter Formats extraction results as Markdown
     */
    public function __construct(
        private UrlValidator      $urlValidator,
        private HtmlFetcher       $htmlFetcher,
        private ?SpaRenderer      $spaRenderer,
        private ScriptAnalyzer    $scriptAnalyzer,
        private ApiFollower       $apiFollower,
        private ContentExtractor  $contentExtractor,
        private DomProcessor      $domProcessor,
        private HtmlFormatter     $htmlFormatter,
        private JsonFormatter     $jsonFormatter,
        private MarkdownFormatter $markdownFormatter,
    ) {}

    /**
     * Handles an incoming HTTP request and returns a response or a promise.
     *
     * Returns a PromiseInterface for SPA requests (async rendering via child process).
     * Returns a Response directly for all other request types.
     */
    public function handle(ServerRequestInterface $request): Response|PromiseInterface
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
        } catch (InvalidArgumentException $e) {
            return $this->cors($this->errorResponse(400, 'INVALID_PARAMETER', $e->getMessage()));
        }

        $result = $this->processUrl($options);
        if ($result instanceof PromiseInterface) {
            return $result;
        }
        return $this->cors($result);
    }

    /**
     * Executes the full URL processing pipeline: validation, fetch, script analysis, API following, and formatting.
     *
     * @param array{url: string, format: string, followApis: bool, isFull: bool, isSpa: bool, timezone: ?string, timeout: int, maxApis: int} $options Parsed request options
     * @return Response|PromiseInterface HTTP response or a promise that resolves to one (for SPA requests)
     */
    private function processUrl(array $options): Response|PromiseInterface
    {
        $url = $options['url'];
        $format = $options['format'];
        $followApis = $options['followApis'];
        $isFull = $options['isFull'];
        $isSpa = $options['isSpa'];
        $timezone = $options['timezone'];
        $timeout = $options['timeout'];
        $maxApis = $options['maxApis'];

        $startTime = hrtime(true);

        // 1. URL 検証
        try {
            $this->urlValidator->validate($url);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse(400, 'INVALID_URL', $e->getMessage());
        } catch (RuntimeException $e) {
            return $this->errorResponse(403, 'BLOCKED_URL', $e->getMessage());
        } catch (Throwable $e) {
            return $this->errorResponse(500, 'INTERNAL_ERROR', 'URL validation failed: ' . $e->getMessage());
        }

        // 2. HTML 取得
        if ($isSpa && !$this->spaRenderer) {
            return $this->errorResponse(501, 'SPA_DISABLED', 'SPA rendering is not enabled on this instance. Set ENABLE_SPA=true and configure Chromium.');
        }
        if ($isSpa && $this->spaRenderer) {
            return $this->spaRenderer->renderAsync($url, $timeout)->then(
                function (array $fetchResult) use ($url, $format, $followApis, $isFull, $maxApis, $timezone, $startTime) {
                    $fetchResult['contentType'] = 'text/html';
                    try {
                        return $this->cors($this->buildResponse($url, $format, $followApis, $isFull, $maxApis, $timezone, $startTime, $fetchResult));
                    } catch (DateInvalidTimeZoneException|DateMalformedStringException $e) {
                        return $this->cors($this->errorResponse(500, 'INTERNAL_ERROR', 'Date processing failed: ' . $e->getMessage()));
                    }
                },
                function (Throwable $e) {
                    $msg = $e->getMessage();
                    if (str_contains(strtolower($msg), 'queue is full')) {
                        return $this->cors($this->errorResponse(503, 'SPA_QUEUE_FULL', $msg));
                    }
                    if (str_contains(strtolower($msg), 'timeout')) {
                        return $this->cors($this->errorResponse(504, 'TIMEOUT', 'SPA rendering timed out'));
                    }
                    return $this->cors($this->errorResponse(502, 'FETCH_FAILED', 'SPA rendering failed: ' . $msg));
                }
            );
        }

        try {
            $fetchResult = $this->htmlFetcher->fetch($url, $timeout);
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            if (str_contains(strtolower($msg), 'timeout') || str_contains(strtolower($msg), 'timed out')) {
                return $this->errorResponse(504, 'TIMEOUT', 'Upstream request timed out');
            }
            if (str_contains(strtolower($msg), 'maximum size')) {
                return $this->errorResponse(502, 'RESPONSE_TOO_LARGE', $msg);
            }
            return $this->errorResponse(502, 'FETCH_FAILED', $msg);
        } catch (Throwable $e) {
            return $this->errorResponse(500, 'INTERNAL_ERROR', 'Fetch failed: ' . $e->getMessage());
        }

        try {
            return $this->buildResponse($url, $format, $followApis, $isFull, $maxApis, $timezone, $startTime, $fetchResult);
        } catch (DateInvalidTimeZoneException|DateMalformedStringException $e) {
            return $this->errorResponse(500, 'INTERNAL_ERROR', 'Date processing failed: ' . $e->getMessage());
        }
    }

    /**
     * Builds the final HTTP response from fetched HTML by running extraction, processing, and formatting.
     *
     * @param string $url Original request URL
     * @param string $format Output format (html, json, or markdown)
     * @param bool $followApis Whether to discover and follow API endpoints
     * @param bool $isFull Whether to include head metadata in output
     * @param int $maxApis Maximum number of API endpoints to follow
     * @param string|null $timezone IANA timezone for the fetchedAt timestamp, or null for server default
     * @param int $startTime High-resolution start time from hrtime(true) for timing statistics
     * @param array{html: string, finalUrl: string, status: int, contentType: string} $fetchResult Raw fetch result from HtmlFetcher or SpaRenderer
     * @return Response Formatted HTTP response with content and timing headers
     * @throws DateInvalidTimeZoneException If the provided timezone identifier is invalid
     * @throws DateMalformedStringException If date construction fails
     */
    private function buildResponse(string $url, string $format, bool $followApis, bool $isFull, int $maxApis, ?string $timezone, int $startTime, array $fetchResult): Response
    {
        $html = $fetchResult['html'];
        $finalUrl = $fetchResult['finalUrl'];
        $originalSize = strlen($html);
        $fetchTimeMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        $apiResult = ['apiData' => [], 'failedApis' => [], 'detectedApis' => []];
        if ($followApis) {
            $endpoints = $this->scriptAnalyzer->extractFromHtml($html);

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
                } catch (Throwable $e) {
                    error_log('[LiteStrip] External JS fetch failed: ' . $resolvedSrc . ' — ' . $e->getMessage());
                }
            }

            $endpoints = array_values(array_unique($endpoints));
            if (!empty($endpoints)) {
                $apiResult = $this->apiFollower->follow($finalUrl, $endpoints, $maxApis);
            }
        }

        $processedHtml = $this->contentExtractor->extract($html, $isFull);
        $cleanHtml = $this->domProcessor->process($processedHtml);
        $title = $this->extractTitle($html);
        $meta = $this->extractMeta($html);

        $processTimeMs = (int) ((hrtime(true) - $startTime) / 1_000_000) - $fetchTimeMs;
        $totalTimeMs = $fetchTimeMs + $processTimeMs;

        $commonHeaders = [
            'X-LiteStrip-Version' => ServerConfig::VERSION,
            'X-LiteStrip-Fetch-Time' => (string) $fetchTimeMs,
            'X-LiteStrip-Process-Time' => (string) $processTimeMs,
            'X-LiteStrip-APIs-Found' => (string) count($apiResult['detectedApis']),
            'X-LiteStrip-APIs-Followed' => (string) (count($apiResult['apiData']) + count($apiResult['failedApis'])),
            'X-LiteStrip-Original-Size' => (string) $originalSize,
        ];

        $contentType = match ($format) {
            'json' => 'application/json; charset=utf-8',
            'markdown' => 'text/markdown; charset=utf-8',
            default => 'text/html; charset=utf-8',
        };

        $body = match ($format) {
            'json' => $this->buildJsonBody($url, $finalUrl, $title, $cleanHtml, $meta, $apiResult, $timezone, $fetchTimeMs, $processTimeMs, $totalTimeMs, $originalSize),
            'markdown' => $this->markdownFormatter->format($cleanHtml, $apiResult['apiData']),
            default => $this->htmlFormatter->format($url, $cleanHtml, $apiResult['apiData'], $apiResult['failedApis']),
        };

        $commonHeaders['X-LiteStrip-Output-Size'] = (string) strlen($body);
        $commonHeaders['Content-Type'] = $contentType;

        if ($format === 'html') {
            $commonHeaders['Content-Security-Policy'] = "default-src 'none'; img-src 'none'; media-src 'none'; frame-src 'none'; script-src 'none'; style-src 'none'; base-uri 'none'; form-action 'none'";
        }

        return new Response(200, $commonHeaders, $body);
    }

    /**
     * Constructs the JSON response body containing all extraction data and timing statistics.
     *
     * @param string $url Original request URL
     * @param string $finalUrl Final URL after following redirects
     * @param string $title Extracted page title
     * @param string $cleanHtml Processed and attribute-stripped HTML content
     * @param array{description: ?string, language: ?string, ogImage: ?string} $meta Extracted page metadata
     * @param array{apiData: list<array>, failedApis: list<array>, detectedApis: list<string>} $apiResult API following results
     * @param string|null $timezone IANA timezone for the fetchedAt timestamp, or null for server default
     * @param int $fetchTimeMs Time spent fetching the HTML in milliseconds
     * @param int $processTimeMs Time spent processing the HTML in milliseconds
     * @param int $totalTimeMs Total elapsed time in milliseconds
     * @param int $originalSize Original HTML response size in bytes
     * @return string JSON-encoded response body
     * @throws DateInvalidTimeZoneException If the provided timezone identifier is invalid
     * @throws DateMalformedStringException If date construction fails
     */
    private function buildJsonBody(string $url, string $finalUrl, string $title, string $cleanHtml, array $meta, array $apiResult, ?string $timezone, int $fetchTimeMs, int $processTimeMs, int $totalTimeMs, int $originalSize): string
    {
        $tz = $timezone ? new DateTimeZone($timezone) : null;
        $fetchedAt = new DateTimeImmutable('now', $tz);

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
                'fetchedAt' => $fetchedAt->format('c'),
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
        return $this->jsonFormatter->format($jsonData);
    }

    /**
     * Parses and validates request parameters from query string (GET) or JSON body (POST).
     *
     * @param ServerRequestInterface $request Incoming HTTP request
     * @return array{url: string, format: string, followApis: bool, isFull: bool, isSpa: bool, timezone: ?string, timeout: int, maxApis: int} Validated request options
     * @throws InvalidArgumentException If any parameter is missing, invalid, or out of range
     */
    private function parseOptions(ServerRequestInterface $request): array
    {
        $method = $request->getMethod();

        if ($method === 'POST') {
            $contentType = $request->getHeaderLine('Content-Type');
            if (!str_contains($contentType, 'application/json')) {
                throw new InvalidArgumentException('POST requests must use Content-Type: application/json');
            }
            $body = json_decode((string) $request->getBody(), true);
            if (!is_array($body)) {
                throw new InvalidArgumentException('Invalid JSON body');
            }
            $params = $body;
        } else {
            $params = $request->getQueryParams();
        }

        $url = $params['url'] ?? '';
        if ($url === '') {
            throw new InvalidArgumentException('Missing required parameter: url');
        }

        $format = $params['format'] ?? ServerConfig::DEFAULT_FORMAT;
        if (!in_array($format, ServerConfig::ALLOWED_FORMATS, true)) {
            throw new InvalidArgumentException('Invalid format. Allowed: ' . implode(', ', ServerConfig::ALLOWED_FORMATS));
        }

        $followApis = filter_var($params['follow_apis'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $isFull = filter_var($params['is_full'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $isSpa = filter_var($params['is_spa'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $timezone = $params['timezone'] ?? null;
        if ($timezone !== null) {
            try {
                new DateTimeZone($timezone);
            } catch (DateInvalidTimeZoneException) {
                throw new InvalidArgumentException("Invalid timezone: $timezone");
            }
        }

        $timeout = (int) ($params['timeout'] ?? ServerConfig::DEFAULT_TIMEOUT);
        if ($timeout < 1 || $timeout > ServerConfig::MAX_TIMEOUT) {
            throw new InvalidArgumentException('timeout must be between 1 and ' . ServerConfig::MAX_TIMEOUT);
        }

        $maxApis = (int) ($params['max_apis'] ?? ServerConfig::DEFAULT_MAX_APIS);
        if ($maxApis < 1 || $maxApis > ServerConfig::MAX_MAX_APIS) {
            throw new InvalidArgumentException('max_apis must be between 1 and ' . ServerConfig::MAX_MAX_APIS);
        }

        return compact('url', 'format', 'followApis', 'isFull', 'isSpa', 'timezone', 'timeout', 'maxApis');
    }

    /**
     * Returns a JSON health check response with status and version.
     *
     * @return Response JSON response with ok status and application version
     */
    private function health(): Response
    {
        return new Response(200, [
            'Content-Type' => 'application/json; charset=utf-8',
        ], json_encode(['status' => 'ok', 'version' => ServerConfig::VERSION]));
    }

    /**
     * Returns an HTML documentation page describing available API parameters and usage.
     *
     * @return Response HTML response with API documentation
     */
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
<li><code>format</code> — json (default), html, markdown</li>
<li><code>follow_apis</code> — true (default), false</li>
<li><code>is_full</code> — false (default), true (include head metadata)</li>
<li><code>is_spa</code> — false (default), true (render via headless Chromium, requires ENABLE_SPA=true)</li>
<li><code>timezone</code> — IANA timezone for fetchedAt (e.g. Asia/Tokyo, UTC)</li>
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

    /**
     * Builds a JSON error response with the given HTTP status code and error details.
     *
     * @param int $status HTTP status code
     * @param string $code Machine-readable error code (e.g. BLOCKED_URL, TIMEOUT)
     * @param string $message Human-readable error description
     * @return Response JSON error response
     */
    private function errorResponse(int $status, string $code, string $message): Response
    {
        return new Response($status, [
            'Content-Type' => 'application/json; charset=utf-8',
        ], $this->jsonFormatter->formatError($code, $message));
    }

    /**
     * Adds CORS and security headers to the response.
     *
     * @param Response $response The response to augment with CORS headers
     * @return Response Response with Access-Control-*, X-Content-Type-Options, and Referrer-Policy headers
     */
    private function cors(Response $response): Response
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    /**
     * Extracts the page title from the HTML <title> tag.
     *
     * @param string $html Full HTML document string
     * @return string Decoded page title, or empty string if no title tag is found
     */
    private function extractTitle(string $html): string
    {
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        }
        return '';
    }

    /**
     * Extracts metadata (description, language, og:image) from the HTML document.
     *
     * @param string $html Full HTML document string
     * @return array{description: ?string, language: ?string, ogImage: ?string} Extracted metadata values, null if not found
     */
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

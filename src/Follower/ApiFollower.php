<?php

declare(strict_types=1);

namespace LiteStrip\Follower;

use LiteStrip\Config\ServerConfig;
use LiteStrip\Fetcher\HtmlFetcher;
use LiteStrip\Fetcher\UrlValidator;
use Throwable;

/**
 * Follows API endpoints discovered by ScriptAnalyzer.
 *
 * Resolves relative URLs, enforces same-origin policy, validates each endpoint
 * against SSRF blocklist, and fetches their responses. Results are split into
 * successful (apiData) and failed (failedApis) arrays.
 */
readonly class ApiFollower
{
    public function __construct(
        private HtmlFetcher  $fetcher,
        private UrlValidator $urlValidator,
    ) {}

    /**
     * Resolves, validates, and fetches discovered API endpoints.
     *
     * @param string $baseUrl Base page URL for relative URL resolution and same-origin check
     * @param list<string> $rawEndpoints Raw endpoint URLs from ScriptAnalyzer (may be relative)
     * @param int $maxApis Maximum number of endpoints to follow
     * @return array{apiData: list<array>, failedApis: list<array>, detectedApis: list<string>}
     */
    public function follow(string $baseUrl, array $rawEndpoints, int $maxApis = ServerConfig::DEFAULT_MAX_APIS): array
    {
        $detectedApis = [];
        $validEndpoints = [];

        foreach ($rawEndpoints as $raw) {
            $resolved = $this->urlValidator->resolveUrl($baseUrl, $raw);
            $detectedApis[] = $resolved;

            if (!$this->urlValidator->isSameOrigin($baseUrl, $resolved)) {
                continue;
            }

            try {
                $this->urlValidator->validate($resolved);
                $validEndpoints[] = $resolved;
            } catch (Throwable) {
                // SSRF blocked — skip
            }

            if (count($validEndpoints) >= $maxApis) {
                break;
            }
        }

        if (empty($validEndpoints)) {
            return [
                'apiData' => [],
                'failedApis' => [],
                'detectedApis' => $detectedApis,
            ];
        }

        $apiData = [];
        $failedApis = [];

        foreach ($validEndpoints as $url) {
            try {
                $result = $this->fetcher->fetchWithStatus($url);
                $body = $result['body'];
                $status = $result['status'];
                $reasonPhrase = $result['reasonPhrase'];
                $contentType = $result['contentType'];

                $data = json_decode($body, true);
                $isJson = json_last_error() === JSON_ERROR_NONE;
                $parsedData = $isJson ? $data : $body;

                if ($status >= 400) {
                    $failedApis[] = [
                        'url' => $url,
                        'status' => $status,
                        'statusMessage' => $reasonPhrase,
                        'contentType' => $contentType,
                        'data' => $parsedData,
                        'error' => "HTTP_$status",
                    ];
                } else {
                    $apiData[] = [
                        'url' => $url,
                        'status' => $status,
                        'contentType' => $isJson ? 'application/json' : $contentType,
                        'data' => $parsedData,
                    ];
                }
            } catch (Throwable $e) {
                $failedApis[] = [
                    'url' => $url,
                    'status' => 0,
                    'statusMessage' => '',
                    'contentType' => '',
                    'data' => null,
                    'error' => $this->classifyError($e->getMessage()),
                ];
            }
        }

        return [
            'apiData' => $apiData,
            'failedApis' => $failedApis,
            'detectedApis' => $detectedApis,
        ];
    }

    /**
     * Classifies an exception message into a standardized error code.
     */
    private function classifyError(string $message): string
    {
        $lower = strtolower($message);
        if (str_contains($lower, 'timeout') || str_contains($lower, 'timed out')) {
            return 'TIMEOUT';
        }
        if (str_contains($lower, 'private network') || str_contains($lower, 'blocked')) {
            return 'BLOCKED_URL';
        }
        if (str_contains($lower, 'maximum size') || str_contains($lower, 'too large')) {
            return 'RESPONSE_TOO_LARGE';
        }
        return 'FETCH_FAILED';
    }
}

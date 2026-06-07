<?php

declare(strict_types=1);

namespace LiteStrip\Formatter;

class JsonFormatter
{
    /**
     * @param array{
     *     url: string,
     *     finalUrl: string,
     *     title: string,
     *     contentHtml: string,
     *     contentText: string,
     *     meta: array,
     *     detectedApis: list<string>,
     *     apiData: list<array>,
     *     failedApis: list<array>,
     *     warnings: list<string>,
     *     stats: array
     * } $data
     * @return string JSON 文字列
     */
    public function format(array $data): string
    {
        $response = [
            'ok' => true,
            'data' => [
                'url' => $data['url'],
                'finalUrl' => $data['finalUrl'],
                'title' => $data['title'],
                'contentHtml' => $data['contentHtml'],
                'contentText' => $data['contentText'],
                'meta' => $data['meta'],
                'detectedApis' => $data['detectedApis'],
                'apiData' => $data['apiData'],
                'failedApis' => $data['failedApis'],
                'warnings' => $data['warnings'],
            ],
            'stats' => $data['stats'],
        ];

        return json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public function formatError(string $code, string $message): string
    {
        return json_encode([
            'ok' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

<?php

declare(strict_types=1);

namespace LiteStrip\Formatter;

class HtmlFormatter
{
    /**
     * @param string      $url        元 URL
     * @param string      $contentHtml 属性剥がし済み HTML
     * @param list<array> $apiData    追従成功した API データ
     * @param list<array> $failedApis 追従失敗した API データ
     * @return string 出力 HTML
     */
    public function format(string $url, string $contentHtml, array $apiData = [], array $failedApis = []): string
    {
        $escapedUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

        $output = "<!-- LiteStrip: {$escapedUrl} -->\n";
        $output .= "<p>Source: <a href=\"{$escapedUrl}\">{$escapedUrl}</a></p>\n";
        $output .= $contentHtml;

        if (!empty($apiData)) {
            $count = count($apiData);
            $output .= "\n<!-- LiteStrip: API Data ({$count} endpoint" . ($count > 1 ? 's' : '') . " followed) -->\n";

            foreach ($apiData as $api) {
                $output .= $this->formatApiSection($api);
            }
        }

        if (!empty($failedApis)) {
            $count = count($failedApis);
            $output .= "\n<!-- LiteStrip: Failed APIs ({$count}) -->\n";

            foreach ($failedApis as $api) {
                $output .= $this->formatFailedApiSection($api);
            }
        }

        return $output;
    }

    private function formatApiSection(array $api): string
    {
        $apiUrl = htmlspecialchars($api['url'], ENT_QUOTES, 'UTF-8');
        $status = $api['status'] ?? 200;
        $data = $this->formatData($api['data'] ?? null);

        $output = "<section>\n";
        $output .= "  <h2>{$apiUrl} ({$status})</h2>\n";
        $output .= "  <pre>{$data}</pre>\n";
        $output .= "</section>\n";
        return $output;
    }

    private function formatFailedApiSection(array $api): string
    {
        $apiUrl = htmlspecialchars($api['url'], ENT_QUOTES, 'UTF-8');
        $status = $api['status'] ?? 0;
        $statusMessage = htmlspecialchars($api['statusMessage'] ?? '', ENT_QUOTES, 'UTF-8');
        $error = htmlspecialchars($api['error'] ?? 'UNKNOWN', ENT_QUOTES, 'UTF-8');

        $output = "<section>\n";
        $output .= "  <h2>{$apiUrl} ({$status} {$statusMessage}) — {$error}</h2>\n";

        if (isset($api['data']) && $api['data'] !== null) {
            $data = $this->formatData($api['data']);
            $output .= "  <pre>{$data}</pre>\n";
        }

        $output .= "</section>\n";
        return $output;
    }

    private function formatData(mixed $data): string
    {
        if ($data === null) {
            return '';
        }
        if (is_string($data)) {
            return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        }
        return htmlspecialchars(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

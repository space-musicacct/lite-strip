<?php

declare(strict_types=1);

namespace LiteStrip\Formatter;

class HtmlFormatter
{
    /**
     * @param string               $url         元 URL
     * @param string               $contentHtml 属性剥がし済み HTML
     * @param list<array>          $apiData     追従成功した API データ
     * @return string 出力 HTML
     */
    public function format(string $url, string $contentHtml, array $apiData = []): string
    {
        $output = "<!-- LiteStrip: {$url} -->\n";
        $output .= $contentHtml;

        if (!empty($apiData)) {
            $count = count($apiData);
            $output .= "\n<!-- LiteStrip: API Data ({$count} endpoint" . ($count > 1 ? 's' : '') . " followed) -->\n";

            foreach ($apiData as $api) {
                $apiUrl = htmlspecialchars($api['url'], ENT_QUOTES, 'UTF-8');
                $data = is_string($api['data'])
                    ? htmlspecialchars($api['data'], ENT_QUOTES, 'UTF-8')
                    : htmlspecialchars(json_encode($api['data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8');

                $output .= "<section>\n";
                $output .= "  <h2>{$apiUrl}</h2>\n";
                $output .= "  <pre>{$data}</pre>\n";
                $output .= "</section>\n";
            }
        }

        return $output;
    }
}

<?php

declare(strict_types=1);

namespace LiteStrip\Formatter;

use League\HTMLToMarkdown\HtmlConverter;

class MarkdownFormatter
{
    private HtmlConverter $converter;

    public function __construct()
    {
        $this->converter = new HtmlConverter([
            'strip_tags' => false,
            'hard_break' => true,
            'header_style' => 'atx',
        ]);
    }

    /**
     * @param string      $contentHtml 属性剥がし済み HTML
     * @param list<array> $apiData     追従成功した API データ
     * @return string Markdown 文字列
     */
    public function format(string $contentHtml, array $apiData = []): string
    {
        $markdown = $this->converter->convert($contentHtml);

        if (!empty($apiData)) {
            $markdown .= "\n\n---\n\n## API Data\n";

            foreach ($apiData as $api) {
                $markdown .= "\n### " . $api['url'] . "\n\n";
                $data = is_string($api['data'])
                    ? $api['data']
                    : json_encode($api['data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $markdown .= "```json\n$data\n```\n";
            }
        }

        return $markdown;
    }
}

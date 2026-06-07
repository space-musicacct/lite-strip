<?php

declare(strict_types=1);

namespace LiteStrip\Formatter;

use League\HTMLToMarkdown\HtmlConverter;

/**
 * Formats extraction results as Markdown using league/html-to-markdown.
 */
class MarkdownFormatter
{
    /** @var HtmlConverter HTML-to-Markdown converter instance */
    private HtmlConverter $converter;

    /**
     * Creates a new Markdown formatter with a preconfigured HTML-to-Markdown converter.
     */
    public function __construct()
    {
        $this->converter = new HtmlConverter([
            'strip_tags' => false,
            'hard_break' => true,
            'header_style' => 'atx',
        ]);
    }

    /**
     * Converts attribute-stripped HTML to Markdown, appending API data as code blocks.
     *
     * @param string $contentHtml Attribute-stripped HTML content
     * @param list<array> $apiData Successfully fetched API responses
     * @return string Markdown output
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

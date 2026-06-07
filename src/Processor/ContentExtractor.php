<?php

declare(strict_types=1);

namespace LiteStrip\Processor;

use DOMDocument;

/**
 * Extracts the relevant portion of an HTML document for AI consumption.
 *
 * By default returns the <body> content (header, main, footer included).
 * With $more=true, includes <head> metadata as well.
 */
class ContentExtractor
{
    /**
     * Extracts content from an HTML document.
     *
     * @param string $html Full HTML document string
     * @param bool $more If true, return the entire document including <head>; if false, return <body> only
     * @return string Extracted HTML
     */
    public function extract(string $html, bool $more = false): string
    {
        if ($more) {
            return $html;
        }

        $doc = new DOMDocument();
        @$doc->loadHTML(
            '<meta charset="UTF-8">' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING
        );

        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body) {
            return $html;
        }

        $output = '';
        foreach ($body->childNodes as $child) {
            $output .= $doc->saveHTML($child);
        }

        return trim($output);
    }
}

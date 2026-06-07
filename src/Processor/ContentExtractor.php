<?php

declare(strict_types=1);

namespace LiteStrip\Processor;

class ContentExtractor
{
    /**
     * @param string $html  完全な HTML 文字列
     * @param bool   $more  true なら <head> も含めて返す、false なら <body> の中身のみ
     * @return string 抽出済み HTML
     */
    public function extract(string $html, bool $more = false): string
    {
        if ($more) {
            return $html;
        }

        $doc = new \DOMDocument();
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

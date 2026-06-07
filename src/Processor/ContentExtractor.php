<?php

declare(strict_types=1);

namespace LiteStrip\Processor;

class ContentExtractor
{
    /** @var list<string> 優先するメインコンテンツ要素 */
    private const MAIN_SELECTORS = ['main', 'article'];

    /** @var list<string> 削除する非コンテンツ要素 */
    private const NON_CONTENT_TAGS = [
        'nav', 'header', 'footer', 'aside',
    ];

    /** @var list<string> 削除する ARIA ロール */
    private const NON_CONTENT_ROLES = [
        'navigation', 'banner', 'contentinfo',
    ];

    /**
     * @param string $html HTML 文字列
     * @return string メインコンテンツのみの HTML
     */
    public function extract(string $html): string
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NOIMPLIED
        );

        $xpath = new \DOMXPath($doc);

        // <main>, <article>, [role="main"] を探す
        foreach (self::MAIN_SELECTORS as $tag) {
            $elements = $doc->getElementsByTagName($tag);
            if ($elements->length > 0) {
                return $doc->saveHTML($elements->item(0));
            }
        }

        $roleMain = $xpath->query('//*[@role="main"]');
        if ($roleMain->length > 0) {
            return $doc->saveHTML($roleMain->item(0));
        }

        // メインコンテンツ要素がない場合、非コンテンツ要素を削除して返す
        $this->removeNonContent($doc, $xpath);

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

    private function removeNonContent(\DOMDocument $doc, \DOMXPath $xpath): void
    {
        foreach (self::NON_CONTENT_TAGS as $tag) {
            $elements = $doc->getElementsByTagName($tag);
            $toRemove = [];
            for ($i = 0; $i < $elements->length; $i++) {
                $toRemove[] = $elements->item($i);
            }
            foreach ($toRemove as $el) {
                $el->parentNode?->removeChild($el);
            }
        }

        foreach (self::NON_CONTENT_ROLES as $role) {
            $elements = $xpath->query('//*[@role="' . $role . '"]');
            foreach ($elements as $el) {
                $el->parentNode?->removeChild($el);
            }
        }
    }
}

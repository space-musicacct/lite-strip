<?php

declare(strict_types=1);

namespace LiteStrip\Processor;

use LiteStrip\Config\AllowedAttributes;

class DomProcessor
{
    /** @var list<string> 完全に削除する要素 */
    private const REMOVE_ELEMENTS = [
        'script', 'style', 'noscript', 'svg',
    ];

    /** @var list<string> rel="stylesheet" で削除する link */
    private const REMOVE_LINK_RELS = ['stylesheet'];

    /** @var list<string> 空でも削除しない void 要素 */
    private const KEEP_EMPTY = [
        'img', 'br', 'hr', 'input', 'time', 'meta', 'link',
    ];

    /**
     * @param string $html          処理対象の HTML
     * @param bool   $extractBefore ScriptAnalyzer で script を抽出済みか
     * @return string 属性剥がし済みのクリーンな HTML
     */
    public function process(string $html): string
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NOIMPLIED
        );

        $this->removeElements($doc);
        $this->stripAttributes($doc);
        $this->removeEmptyElements($doc);

        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body) {
            return '';
        }

        $output = '';
        foreach ($body->childNodes as $child) {
            $output .= $doc->saveHTML($child);
        }

        return $this->normalizeWhitespace(trim($output));
    }

    private function removeElements(\DOMDocument $doc): void
    {
        foreach (self::REMOVE_ELEMENTS as $tagName) {
            $elements = $doc->getElementsByTagName($tagName);
            $toRemove = [];
            for ($i = 0; $i < $elements->length; $i++) {
                $toRemove[] = $elements->item($i);
            }
            foreach ($toRemove as $el) {
                $el->parentNode?->removeChild($el);
            }
        }

        // <link rel="stylesheet"> の削除
        $links = $doc->getElementsByTagName('link');
        $toRemove = [];
        for ($i = 0; $i < $links->length; $i++) {
            $link = $links->item($i);
            $rel = strtolower($link->getAttribute('rel'));
            if (in_array($rel, self::REMOVE_LINK_RELS, true)) {
                $toRemove[] = $link;
            }
        }
        foreach ($toRemove as $el) {
            $el->parentNode?->removeChild($el);
        }

        // コメントの削除
        $xpath = new \DOMXPath($doc);
        $comments = $xpath->query('//comment()');
        foreach ($comments as $comment) {
            $comment->parentNode?->removeChild($comment);
        }
    }

    private function stripAttributes(\DOMDocument $doc): void
    {
        $xpath = new \DOMXPath($doc);
        $allElements = $xpath->query('//*');

        foreach ($allElements as $element) {
            if (!$element instanceof \DOMElement) {
                continue;
            }

            $tagName = strtolower($element->tagName);
            $allowed = AllowedAttributes::forTag($tagName);
            $transforms = AllowedAttributes::safetyTransforms($tagName);

            $attrsToRemove = [];
            $attrsToAdd = [];

            foreach ($element->attributes as $attr) {
                $attrName = strtolower($attr->name);

                // 安全化変換 (iframe src → data-src, form action → data-action)
                if (isset($transforms[$attrName])) {
                    $attrsToAdd[$transforms[$attrName]] = $attr->value;
                    $attrsToRemove[] = $attr->name;
                    continue;
                }

                if (!in_array($attrName, $allowed, true)) {
                    $attrsToRemove[] = $attr->name;
                }
            }

            foreach ($attrsToRemove as $name) {
                $element->removeAttribute($name);
            }
            foreach ($attrsToAdd as $name => $value) {
                $element->setAttribute($name, $value);
            }
        }
    }

    private function removeEmptyElements(\DOMDocument $doc): void
    {
        $changed = true;
        while ($changed) {
            $changed = false;
            $xpath = new \DOMXPath($doc);
            $allElements = $xpath->query('//*');

            foreach ($allElements as $element) {
                if (!$element instanceof \DOMElement) {
                    continue;
                }

                $tagName = strtolower($element->tagName);

                if (in_array($tagName, self::KEEP_EMPTY, true)) {
                    continue;
                }

                if ($tagName === 'html' || $tagName === 'head' || $tagName === 'body') {
                    continue;
                }

                $text = trim($element->textContent);
                if ($text === '' && $element->childNodes->length === 0) {
                    $element->parentNode?->removeChild($element);
                    $changed = true;
                }
            }
        }
    }

    private function normalizeWhitespace(string $html): string
    {
        $html = preg_replace("/\n{3,}/", "\n\n", $html);
        $html = preg_replace("/[ \t]+\n/", "\n", $html);
        return $html;
    }
}

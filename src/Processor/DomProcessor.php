<?php

declare(strict_types=1);

namespace LiteStrip\Processor;

use DOMDocument;
use DOMElement;
use DOMXPath;
use LiteStrip\Config\AllowedAttributes;

/**
 * Processes HTML by stripping non-semantic attributes, removing noise elements,
 * and cleaning up empty containers.
 *
 * Detects whether the input is a full HTML document (with <body>) or a fragment,
 * and handles each case appropriately to preserve the document structure.
 */
class DomProcessor
{
    /** @var list<string> Elements to remove entirely */
    private const array REMOVE_ELEMENTS = [
        'script', 'style', 'noscript', 'svg',
    ];

    /** @var list<string> Link rel values that trigger removal */
    private const array REMOVE_LINK_RELS = ['stylesheet'];

    /** @var list<string> Void/semantic elements to keep even when empty */
    private const array KEEP_EMPTY = [
        'img', 'br', 'hr', 'input', 'time', 'meta', 'link',
    ];

    /**
     * Strips attributes, removes noise elements, and normalizes whitespace.
     *
     * @param string $html Input HTML (full document or fragment)
     * @return string Cleaned HTML with only semantic attributes preserved
     */
    public function process(string $html): string
    {
        $isFullDocument = (bool) preg_match('/<body[\s>]/i', $html);

        $doc = new DOMDocument();
        if ($isFullDocument) {
            @$doc->loadHTML(
                '<meta charset="UTF-8">' . $html,
                LIBXML_NOERROR | LIBXML_NOWARNING
            );
        } else {
            @$doc->loadHTML(
                '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>',
                LIBXML_NOERROR | LIBXML_NOWARNING
            );
        }

        $this->removeElements($doc);
        $this->stripAttributes($doc);
        $this->removeEmptyElements($doc);

        $output = '';

        if ($isFullDocument) {
            $head = $doc->getElementsByTagName('head')->item(0);
            if ($head) {
                $headContent = '';
                foreach ($head->childNodes as $child) {
                    $headContent .= $doc->saveHTML($child);
                }
                $headContent = preg_replace('/<meta charset="UTF-8">/i', '', $headContent);
                $headContent = trim($headContent);
                if ($headContent !== '') {
                    $output .= '<head>' . $headContent . '</head>' . "\n";
                }
            }
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body) {
            foreach ($body->childNodes as $child) {
                $output .= $doc->saveHTML($child);
            }
        }

        return $this->normalizeWhitespace(trim($output));
    }

    /**
     * Removes noise elements from the DOM (scripts, styles, noscript, SVG, stylesheet links, and comments).
     *
     * @param DOMDocument $doc The DOM document to modify in place
     * @return void
     */
    private function removeElements(DOMDocument $doc): void
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
        $xpath = new DOMXPath($doc);
        $comments = $xpath->query('//comment()');
        foreach ($comments as $comment) {
            $comment->parentNode?->removeChild($comment);
        }
    }

    /**
     * Strips non-allowed attributes from all elements and applies safety transforms.
     *
     * @param DOMDocument $doc The DOM document to modify in place
     * @return void
     */
    private function stripAttributes(DOMDocument $doc): void
    {
        $xpath = new DOMXPath($doc);
        $allElements = $xpath->query('//*');

        foreach ($allElements as $element) {
            if (!$element instanceof DOMElement) {
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

    /**
     * Recursively removes empty elements from the DOM, preserving void and semantic elements.
     *
     * @param DOMDocument $doc The DOM document to modify in place
     * @return void
     */
    private function removeEmptyElements(DOMDocument $doc): void
    {
        $changed = true;
        while ($changed) {
            $changed = false;
            $xpath = new DOMXPath($doc);
            $allElements = $xpath->query('//*');

            foreach ($allElements as $element) {
                if (!$element instanceof DOMElement) {
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

    /**
     * Normalizes excessive whitespace in the output HTML.
     *
     * @param string $html HTML string to normalize
     * @return string HTML with collapsed blank lines and trimmed trailing spaces
     */
    private function normalizeWhitespace(string $html): string
    {
        $html = preg_replace("/\n{3,}/", "\n\n", $html);
        return preg_replace("/[ \t]+\n/", "\n", $html);
    }
}

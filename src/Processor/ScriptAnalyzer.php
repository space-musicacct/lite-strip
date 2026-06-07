<?php

declare(strict_types=1);

namespace LiteStrip\Processor;

use DOMDocument;

/**
 * Detects API endpoint URLs in JavaScript code using regex pattern matching.
 *
 * Scans inline <script> tags and same-origin external JS files for
 * fetch(), axios.get(), $.get(), and $.getJSON() calls. Handles template
 * literal interpolation (${...}) by stripping variables to extract base URLs.
 */
class ScriptAnalyzer
{
    /** @var list<string> Regex patterns for API endpoint detection */
    private const array PATTERNS = [
        '/fetch\s*\(\s*[\'"`]([^\'"`]+)[\'"`]/i',
        '/axios\.get\s*\(\s*[\'"`]([^\'"`]+)[\'"`]/i',
        '/\$\.get\s*\(\s*[\'"`]([^\'"`]+)[\'"`]/i',
        '/\$\.getJSON\s*\(\s*[\'"`]([^\'"`]+)[\'"`]/i',
    ];

    /** @var list<string> URL path patterns to exclude (tracking, CDN, sourcemaps) */
    private const array EXCLUDED_PATHS = [
        '/pixel', '/beacon', '/track', '/analytics',
        '/cdn-cgi/', '.map',
    ];

    /**
     * Extracts external script src URLs from HTML for further analysis.
     *
     * @param string $html HTML document string
     * @return list<string> Script src attribute values
     */
    public function extractScriptSources(string $html): array
    {
        $sources = [];
        $doc = new DOMDocument();
        @$doc->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING
        );

        $scripts = $doc->getElementsByTagName('script');
        for ($i = 0; $i < $scripts->length; $i++) {
            $script = $scripts->item($i);
            $src = $script->getAttribute('src');
            if ($src !== '') {
                $sources[] = $src;
            }
        }

        return $sources;
    }

    /**
     * Extracts API endpoint URLs from inline <script> tags in HTML.
     *
     * @param string $html HTML document string containing inline scripts
     * @return list<string> Detected API endpoint URLs (unresolved, deduplicated)
     */
    public function extractFromHtml(string $html): array
    {
        $endpoints = [];

        $doc = new DOMDocument();
        @$doc->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING
        );

        $scripts = $doc->getElementsByTagName('script');
        for ($i = 0; $i < $scripts->length; $i++) {
            $script = $scripts->item($i);
            $src = $script->getAttribute('src');
            if ($src !== '') {
                continue;
            }
            $code = $script->textContent;
            $endpoints = array_merge($endpoints, $this->extractFromCode($code));
        }

        return array_values(array_unique($endpoints));
    }

    /**
     * Extracts API endpoint URLs from raw JavaScript source code.
     *
     * @param string $jsCode JavaScript source code
     * @return list<string> Detected API endpoint URLs
     */
    public function extractFromCode(string $jsCode): array
    {
        $endpoints = [];

        foreach (self::PATTERNS as $pattern) {
            if (preg_match_all($pattern, $jsCode, $matches)) {
                foreach ($matches[1] as $url) {
                    if (!$this->isExcluded($url)) {
                        $cleaned = $this->cleanTemplateUrl($url);
                        if ($cleaned !== '' && $cleaned !== '/') {
                            $endpoints[] = $cleaned;
                        }
                    }
                }
            }
        }

        return $endpoints;
    }

    /**
     * Strips template literal interpolations (${...}) and extracts the base URL.
     *
     * Example: `/api/v1/data/?page=${page}&sort=${sort}` becomes `/api/v1/data/`
     */
    private function cleanTemplateUrl(string $url): string
    {
        // ${...} を空文字に置換
        $cleaned = preg_replace('/\$\{[^}]*}/', '', $url);

        // クエリパラメータの値が空になった部分を整理
        // `?page=&sort=` → `?` → 不要なクエリを除去
        $parts = parse_url($cleaned);
        if (!$parts) {
            return $cleaned;
        }

        $result = '';
        if (isset($parts['scheme'])) {
            $result .= $parts['scheme'] . '://';
        }
        if (isset($parts['host'])) {
            $result .= $parts['host'];
        }
        if (isset($parts['port'])) {
            $result .= ':' . $parts['port'];
        }
        $result .= $parts['path'] ?? '/';

        // 値のあるクエリパラメータだけ残す
        if (isset($parts['query'])) {
            parse_str($parts['query'], $queryParams);
            $validParams = array_filter($queryParams, fn($v) => $v !== '');
            if (!empty($validParams)) {
                $result .= '?' . http_build_query($validParams);
            }
        }

        return $result;
    }

    private function isExcluded(string $url): bool
    {
        $lower = strtolower($url);
        return array_any(self::EXCLUDED_PATHS, fn($path) => str_contains($lower, $path));
    }
}

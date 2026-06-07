<?php

declare(strict_types=1);

namespace LiteStrip\Processor;

class ScriptAnalyzer
{
    /** @var list<string> fetch() / axios.get() 等の検出パターン */
    private const PATTERNS = [
        '/fetch\s*\(\s*[\'"`]([^\'"`]+)[\'"`]/i',
        '/axios\.get\s*\(\s*[\'"`]([^\'"`]+)[\'"`]/i',
        '/\$\.get\s*\(\s*[\'"`]([^\'"`]+)[\'"`]/i',
        '/\$\.getJSON\s*\(\s*[\'"`]([^\'"`]+)[\'"`]/i',
    ];

    /** @var list<string> 除外するパスパターン */
    private const EXCLUDED_PATHS = [
        '/pixel', '/beacon', '/track', '/analytics',
        '/cdn-cgi/', '.map',
    ];

    /**
     * @param string $html   HTML 文字列
     * @return list<string>  検出された script src URL (same-origin 外部 JS)
     */
    public function extractScriptSources(string $html): array
    {
        $sources = [];
        $doc = new \DOMDocument();
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
     * @param string $html   HTML 文字列 (inline script 含む)
     * @return list<string>  検出された API endpoint URL (未解決、重複除去済み)
     */
    public function extractFromHtml(string $html): array
    {
        $endpoints = [];

        $doc = new \DOMDocument();
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
     * @param string $jsCode  JavaScript ソースコード
     * @return list<string>   検出された API endpoint URL
     */
    public function extractFromCode(string $jsCode): array
    {
        $endpoints = [];

        foreach (self::PATTERNS as $pattern) {
            if (preg_match_all($pattern, $jsCode, $matches)) {
                foreach ($matches[1] as $url) {
                    if (!$this->isExcluded($url)) {
                        $endpoints[] = $url;
                    }
                }
            }
        }

        return $endpoints;
    }

    private function isExcluded(string $url): bool
    {
        $lower = strtolower($url);
        foreach (self::EXCLUDED_PATHS as $path) {
            if (str_contains($lower, $path)) {
                return true;
            }
        }
        return false;
    }
}

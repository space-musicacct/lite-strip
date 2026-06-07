<?php

declare(strict_types=1);

namespace LiteStrip\Tests\Processor;

use LiteStrip\Processor\ScriptAnalyzer;
use PHPUnit\Framework\TestCase;

class ScriptAnalyzerTest extends TestCase
{
    private ScriptAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new ScriptAnalyzer();
    }

    // --- fetch() detection ---

    public function testDetectsFetchWithSingleQuotes(): void
    {
        $endpoints = $this->analyzer->extractFromCode("fetch('/api/data')");
        $this->assertContains('/api/data', $endpoints);
    }

    public function testDetectsFetchWithDoubleQuotes(): void
    {
        $endpoints = $this->analyzer->extractFromCode('fetch("/api/data")');
        $this->assertContains('/api/data', $endpoints);
    }

    public function testDetectsFetchWithBackticks(): void
    {
        $endpoints = $this->analyzer->extractFromCode('fetch(`/api/data`)');
        $this->assertContains('/api/data', $endpoints);
    }

    public function testDetectsAxiosGet(): void
    {
        $endpoints = $this->analyzer->extractFromCode("axios.get('/api/users')");
        $this->assertContains('/api/users', $endpoints);
    }

    public function testDetectsJqueryGet(): void
    {
        $endpoints = $this->analyzer->extractFromCode("$.get('/api/items')");
        $this->assertContains('/api/items', $endpoints);
    }

    public function testDetectsJqueryGetJSON(): void
    {
        $endpoints = $this->analyzer->extractFromCode("$.getJSON('/api/config')");
        $this->assertContains('/api/config', $endpoints);
    }

    public function testDetectsAbsoluteUrl(): void
    {
        $endpoints = $this->analyzer->extractFromCode("fetch('https://example.com/api/data')");
        $this->assertContains('https://example.com/api/data', $endpoints);
    }

    // --- Template literal handling ---

    public function testStripsTemplateVariablesFromUrl(): void
    {
        $code = 'fetch(`/api/v1/data/?page=${page}&sort=${sort}`)';
        $endpoints = $this->analyzer->extractFromCode($code);
        $this->assertNotEmpty($endpoints);
        $this->assertStringNotContainsString('${', $endpoints[0]);
        $this->assertStringStartsWith('/api/v1/data/', $endpoints[0]);
    }

    public function testStripsTemplateVariablesFromAbsoluteUrl(): void
    {
        $code = 'fetch(`https://example.com/api/items?id=${id}`)';
        $endpoints = $this->analyzer->extractFromCode($code);
        $this->assertNotEmpty($endpoints);
        $this->assertStringStartsWith('https://example.com/api/items', $endpoints[0]);
    }

    // --- Exclusion ---

    public function testExcludesTrackingPixels(): void
    {
        $this->assertEmpty($this->analyzer->extractFromCode("fetch('/pixel/track')"));
        $this->assertEmpty($this->analyzer->extractFromCode("fetch('/beacon/send')"));
        $this->assertEmpty($this->analyzer->extractFromCode("fetch('/analytics/event')"));
    }

    public function testExcludesCdnCgi(): void
    {
        $this->assertEmpty($this->analyzer->extractFromCode("fetch('/cdn-cgi/challenge')"));
    }

    public function testExcludesSourceMaps(): void
    {
        $this->assertEmpty($this->analyzer->extractFromCode("fetch('/app.js.map')"));
    }

    // --- Deduplication ---

    public function testDeduplicatesEndpoints(): void
    {
        $code = "fetch('/api/data'); fetch('/api/data');";
        $endpoints = $this->analyzer->extractFromCode($code);
        $this->assertCount(1, array_unique($endpoints));
    }

    // --- HTML extraction ---

    public function testExtractsFromInlineScript(): void
    {
        $html = '<html><body><script>fetch("/api/inline")</script></body></html>';
        $endpoints = $this->analyzer->extractFromHtml($html);
        $this->assertContains('/api/inline', $endpoints);
    }

    public function testIgnoresExternalScriptInHtmlExtraction(): void
    {
        $html = '<html><body><script src="/js/app.js"></script></body></html>';
        $endpoints = $this->analyzer->extractFromHtml($html);
        $this->assertEmpty($endpoints);
    }

    public function testExtractsScriptSources(): void
    {
        $html = '<html><head><script src="/js/app.js"></script><script src="/js/vendor.js"></script></head></html>';
        $sources = $this->analyzer->extractScriptSources($html);
        $this->assertContains('/js/app.js', $sources);
        $this->assertContains('/js/vendor.js', $sources);
    }

    // --- Edge cases ---

    public function testHandlesEmptyCode(): void
    {
        $this->assertEmpty($this->analyzer->extractFromCode(''));
    }

    public function testHandlesCodeWithNoFetchCalls(): void
    {
        $this->assertEmpty($this->analyzer->extractFromCode('console.log("hello")'));
    }

    public function testRejectsSlashOnly(): void
    {
        $code = "fetch('/')";
        $endpoints = $this->analyzer->extractFromCode($code);
        $this->assertNotContains('/', $endpoints);
    }
}

<?php

declare(strict_types=1);

namespace LiteStrip\Tests\Processor;

use LiteStrip\Processor\ContentExtractor;
use PHPUnit\Framework\TestCase;

class ContentExtractorTest extends TestCase
{
    private ContentExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new ContentExtractor();
    }

    public function testDefaultExtractsBodyOnly(): void
    {
        $html = '<html><head><title>Test</title><meta content="desc"></head><body><h1>Hello</h1></body></html>';
        $result = $this->extractor->extract($html);
        $this->assertStringContainsString('Hello', $result);
        $this->assertStringNotContainsString('<title>', $result);
        $this->assertStringNotContainsString('<head>', $result);
    }

    public function testMoreIncludesHead(): void
    {
        $html = '<html><head><title>Test</title></head><body><h1>Hello</h1></body></html>';
        $result = $this->extractor->extract($html, true);
        $this->assertStringContainsString('<title>Test</title>', $result);
        $this->assertStringContainsString('Hello', $result);
    }

    public function testPreservesHeaderElement(): void
    {
        $html = '<html><body><header><h1>Site Name</h1></header><main><p>Content</p></main></body></html>';
        $result = $this->extractor->extract($html);
        $this->assertStringContainsString('Site Name', $result);
        $this->assertStringContainsString('Content', $result);
    }

    public function testPreservesFooterElement(): void
    {
        $html = '<html><body><main><p>Content</p></main><footer><p>Copyright</p></footer></body></html>';
        $result = $this->extractor->extract($html);
        $this->assertStringContainsString('Content', $result);
        $this->assertStringContainsString('Copyright', $result);
    }

    public function testPreservesNavElement(): void
    {
        $html = '<html><body><nav><a href="/about">About</a></nav><main><p>Content</p></main></body></html>';
        $result = $this->extractor->extract($html);
        $this->assertStringContainsString('About', $result);
    }

    public function testReturnsOriginalWhenNoBody(): void
    {
        $html = '<h1>Fragment</h1><p>No body tag</p>';
        $result = $this->extractor->extract($html);
        $this->assertStringContainsString('Fragment', $result);
    }

    public function testMoreReturnsSameAsInput(): void
    {
        $html = '<html><head><title>T</title></head><body><p>B</p></body></html>';
        $this->assertEquals($html, $this->extractor->extract($html, true));
    }
}

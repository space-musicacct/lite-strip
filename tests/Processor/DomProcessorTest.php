<?php

declare(strict_types=1);

namespace LiteStrip\Tests\Processor;

use LiteStrip\Processor\DomProcessor;
use PHPUnit\Framework\TestCase;

class DomProcessorTest extends TestCase
{
    private DomProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new DomProcessor();
    }

    public function testStripsClassAttribute(): void
    {
        $html = '<body><div class="container"><p class="text">Hello</p></div></body>';
        $result = $this->processor->process($html);
        $this->assertStringNotContainsString('class=', $result);
        $this->assertStringContainsString('Hello', $result);
    }

    public function testStripsIdAttribute(): void
    {
        $html = '<body><div id="main">Content</div></body>';
        $result = $this->processor->process($html);
        $this->assertStringNotContainsString('id=', $result);
    }

    public function testStripsStyleAttribute(): void
    {
        $html = '<body><p style="color:red">Styled</p></body>';
        $result = $this->processor->process($html);
        $this->assertStringNotContainsString('style=', $result);
    }

    public function testStripsDataAttributes(): void
    {
        $html = '<body><div data-value="123" data-toggle="true">Data</div></body>';
        $result = $this->processor->process($html);
        $this->assertStringNotContainsString('data-value', $result);
        $this->assertStringNotContainsString('data-toggle', $result);
    }

    public function testPreservesHref(): void
    {
        $html = '<body><a href="https://example.com" class="link">Link</a></body>';
        $result = $this->processor->process($html);
        $this->assertStringContainsString('href="https://example.com"', $result);
        $this->assertStringNotContainsString('class=', $result);
    }

    public function testPreservesSrcAndAlt(): void
    {
        $html = '<body><img src="/img.jpg" alt="Photo" class="photo"></body>';
        $result = $this->processor->process($html);
        $this->assertStringContainsString('src="/img.jpg"', $result);
        $this->assertStringContainsString('alt="Photo"', $result);
        $this->assertStringNotContainsString('class=', $result);
    }

    public function testPreservesDatetime(): void
    {
        $html = '<body><time datetime="2026-06-07">Today</time></body>';
        $result = $this->processor->process($html);
        $this->assertStringContainsString('datetime="2026-06-07"', $result);
    }

    public function testPreservesColspanRowspan(): void
    {
        $html = '<body><table><tr><td colspan="2" rowspan="3" class="cell">Data</td></tr></table></body>';
        $result = $this->processor->process($html);
        $this->assertStringContainsString('colspan="2"', $result);
        $this->assertStringContainsString('rowspan="3"', $result);
        $this->assertStringNotContainsString('class=', $result);
    }

    public function testTransformsIframeSrcToDataSrc(): void
    {
        $html = '<body><iframe src="https://embed.example.com" class="frame">Loading...</iframe></body>';
        $result = $this->processor->process($html);
        $this->assertStringContainsString('data-src="https://embed.example.com"', $result);
        $this->assertStringNotContainsString(' src=', $result);
    }

    public function testTransformsFormActionToDataAction(): void
    {
        $html = '<body><form action="/search" method="get" class="form"><input type="text"></form></body>';
        $result = $this->processor->process($html);
        $this->assertStringContainsString('data-action="/search"', $result);
        $this->assertStringContainsString('data-method="get"', $result);
        $this->assertStringNotContainsString(' action=', $result);
    }

    public function testRemovesScriptTags(): void
    {
        $html = '<body><p>Keep</p><script>alert(1)</script></body>';
        $result = $this->processor->process($html);
        $this->assertStringNotContainsString('<script', $result);
        $this->assertStringNotContainsString('alert', $result);
        $this->assertStringContainsString('Keep', $result);
    }

    public function testRemovesStyleTags(): void
    {
        $html = '<body><style>.a{color:red}</style><p>Keep</p></body>';
        $result = $this->processor->process($html);
        $this->assertStringNotContainsString('<style', $result);
    }

    public function testRemovesNoscriptTags(): void
    {
        $html = '<body><noscript>Enable JS</noscript><p>Keep</p></body>';
        $result = $this->processor->process($html);
        $this->assertStringNotContainsString('<noscript', $result);
    }

    public function testRemovesStylesheetLinks(): void
    {
        $html = '<body><link rel="stylesheet" href="/style.css"><p>Keep</p></body>';
        $result = $this->processor->process($html);
        $this->assertStringNotContainsString('stylesheet', $result);
    }

    public function testRemovesHtmlComments(): void
    {
        $html = '<body><!-- secret comment --><p>Keep</p></body>';
        $result = $this->processor->process($html);
        $this->assertStringNotContainsString('secret comment', $result);
    }

    public function testRemovesEmptyDivs(): void
    {
        $html = '<body><div class="empty"></div><p>Keep</p></body>';
        $result = $this->processor->process($html);
        $this->assertStringNotContainsString('<div', $result);
    }

    public function testKeepsEmptyImgTag(): void
    {
        $html = '<body><img src="/photo.jpg" alt="Photo"></body>';
        $result = $this->processor->process($html);
        $this->assertStringContainsString('<img', $result);
    }

    public function testKeepsEmptyBrTag(): void
    {
        $html = '<body><p>Line 1<br>Line 2</p></body>';
        $result = $this->processor->process($html);
        $this->assertStringContainsString('<br>', $result);
    }

    public function testKeepsEmptyHrTag(): void
    {
        $html = '<body><hr><p>After</p></body>';
        $result = $this->processor->process($html);
        $this->assertStringContainsString('<hr>', $result);
    }

    public function testPreservesLangAttribute(): void
    {
        $html = '<body><p lang="ja">Japanese</p></body>';
        $result = $this->processor->process($html);
        $this->assertStringContainsString('lang="ja"', $result);
    }
}

<?php

declare(strict_types=1);

namespace LiteStrip\Tests\Config;

use LiteStrip\Config\AllowedAttributes;
use PHPUnit\Framework\TestCase;

class AllowedAttributesTest extends TestCase
{
    public function testAnchorAllowsHrefAndRel(): void
    {
        $allowed = AllowedAttributes::forTag('a');
        $this->assertContains('href', $allowed);
        $this->assertContains('rel', $allowed);
    }

    public function testImgAllowsSrcAndAlt(): void
    {
        $allowed = AllowedAttributes::forTag('img');
        $this->assertContains('src', $allowed);
        $this->assertContains('alt', $allowed);
    }

    public function testGlobalAttributesIncluded(): void
    {
        $allowed = AllowedAttributes::forTag('div');
        $this->assertContains('lang', $allowed);
        $this->assertContains('dir', $allowed);
    }

    public function testUnknownTagOnlyHasGlobals(): void
    {
        $allowed = AllowedAttributes::forTag('custom-element');
        $this->assertEquals(['lang', 'dir'], $allowed);
    }

    public function testIframeSafetyTransform(): void
    {
        $transforms = AllowedAttributes::safetyTransforms('iframe');
        $this->assertArrayHasKey('src', $transforms);
        $this->assertEquals('data-src', $transforms['src']);
    }

    public function testFormSafetyTransform(): void
    {
        $transforms = AllowedAttributes::safetyTransforms('form');
        $this->assertArrayHasKey('action', $transforms);
        $this->assertArrayHasKey('method', $transforms);
        $this->assertEquals('data-action', $transforms['action']);
        $this->assertEquals('data-method', $transforms['method']);
    }

    public function testNoTransformForRegularTags(): void
    {
        $this->assertEmpty(AllowedAttributes::safetyTransforms('div'));
        $this->assertEmpty(AllowedAttributes::safetyTransforms('a'));
    }

    public function testTableCellAttributes(): void
    {
        $td = AllowedAttributes::forTag('td');
        $this->assertContains('colspan', $td);
        $this->assertContains('rowspan', $td);

        $th = AllowedAttributes::forTag('th');
        $this->assertContains('colspan', $th);
        $this->assertContains('rowspan', $th);
    }

    public function testFormElementAttributes(): void
    {
        $input = AllowedAttributes::forTag('input');
        $this->assertContains('type', $input);
        $this->assertContains('name', $input);
        $this->assertContains('value', $input);
        $this->assertContains('placeholder', $input);
    }

    public function testMetaAttributes(): void
    {
        $meta = AllowedAttributes::forTag('meta');
        $this->assertContains('content', $meta);
        $this->assertContains('charset', $meta);
    }
}

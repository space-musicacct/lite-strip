<?php

declare(strict_types=1);

namespace LiteStrip\Tests\Fetcher;

use InvalidArgumentException;
use LiteStrip\Fetcher\UrlValidator;
use PHPUnit\Framework\TestCase;
use React\Dns\Resolver\ResolverInterface;
use React\Promise;
use RuntimeException;

class UrlValidatorTest extends TestCase
{
    private UrlValidator $validator;

    protected function setUp(): void
    {
        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(function (string $host) {
            return match ($host) {
                'example.com' => Promise\resolve('93.184.216.34'),
                'private.test' => Promise\resolve('192.168.1.1'),
                'localhost.test' => Promise\resolve('127.0.0.1'),
                'metadata.test' => Promise\resolve('169.254.169.254'),
                default => Promise\reject(new \Exception('DNS failed')),
            };
        });
        $this->validator = new UrlValidator($resolver);
    }

    public function testAcceptsValidHttpUrl(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validator->validate('https://example.com/page');
    }

    public function testAcceptsHttpUrl(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validator->validate('http://example.com/page');
    }

    public function testRejectsEmptyUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->validator->validate('');
    }

    public function testRejectsFtpScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only http and https');
        $this->validator->validate('ftp://example.com/file');
    }

    public function testRejectsJavascriptScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->validator->validate('javascript:alert(1)');
    }

    public function testRejectsDataScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->validator->validate('data:text/html,<h1>hi</h1>');
    }

    public function testRejectsUrlWithCredentials(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('credentials');
        $this->validator->validate('https://user:pass@example.com');
    }

    public function testRejectsUrlWithUsernameOnly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->validator->validate('https://admin@example.com');
    }

    public function testRejectsTooLongUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maximum length');
        $this->validator->validate('https://example.com/' . str_repeat('a', 2048));
    }

    public function testBlocksPrivateIpDirectly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('private network');
        $this->validator->validate('http://192.168.1.1/admin');
    }

    public function testBlocksLocalhostDirectly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->validator->validate('http://127.0.0.1:8080');
    }

    public function testBlocksMetadataEndpointDirectly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->validator->validate('http://169.254.169.254/latest/meta-data/');
    }

    public function testBlocksDnsResolvingToPrivateIp(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('private network');
        $this->validator->validate('https://private.test/page');
    }

    public function testBlocksDnsResolvingToLocalhost(): void
    {
        $this->expectException(RuntimeException::class);
        $this->validator->validate('https://localhost.test/page');
    }

    public function testBlocksDnsResolvingToMetadata(): void
    {
        $this->expectException(RuntimeException::class);
        $this->validator->validate('https://metadata.test/page');
    }

    public function testBlocksDnsFailure(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DNS resolution failed');
        $this->validator->validate('https://nonexistent.invalid/page');
    }

    // --- isSameOrigin ---

    public function testSameOriginMatchesExactly(): void
    {
        $this->assertTrue($this->validator->isSameOrigin(
            'https://example.com/page1',
            'https://example.com/page2'
        ));
    }

    public function testSameOriginRejectsDifferentScheme(): void
    {
        $this->assertFalse($this->validator->isSameOrigin(
            'https://example.com/page',
            'http://example.com/page'
        ));
    }

    public function testSameOriginRejectsDifferentHost(): void
    {
        $this->assertFalse($this->validator->isSameOrigin(
            'https://example.com/page',
            'https://other.com/page'
        ));
    }

    public function testSameOriginRejectsDifferentPort(): void
    {
        $this->assertFalse($this->validator->isSameOrigin(
            'https://example.com:443/page',
            'https://example.com:8443/page'
        ));
    }

    public function testSameOriginDefaultPorts(): void
    {
        $this->assertTrue($this->validator->isSameOrigin(
            'https://example.com/page',
            'https://example.com:443/page'
        ));
        $this->assertTrue($this->validator->isSameOrigin(
            'http://example.com/page',
            'http://example.com:80/page'
        ));
    }

    // --- resolveUrl ---

    public function testResolvesAbsoluteUrl(): void
    {
        $this->assertEquals(
            'https://other.com/api',
            $this->validator->resolveUrl('https://example.com/page', 'https://other.com/api')
        );
    }

    public function testResolvesRootRelativeUrl(): void
    {
        $this->assertEquals(
            'https://example.com/api/data',
            $this->validator->resolveUrl('https://example.com/page/index.html', '/api/data')
        );
    }

    public function testResolvesRelativeUrl(): void
    {
        $this->assertEquals(
            'https://example.com/page/api/data',
            $this->validator->resolveUrl('https://example.com/page/index.html', 'api/data')
        );
    }

    public function testResolvesProtocolRelativeUrl(): void
    {
        $this->assertEquals(
            'https://cdn.example.com/lib.js',
            $this->validator->resolveUrl('https://example.com/page', '//cdn.example.com/lib.js')
        );
    }
}

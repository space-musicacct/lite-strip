<?php

declare(strict_types=1);

namespace LiteStrip\Tests\Fetcher;

use Exception;
use LiteStrip\Fetcher\HtmlFetcher;
use LiteStrip\Fetcher\UrlValidator;
use PHPUnit\Framework\TestCase;
use React\Dns\Resolver\ResolverInterface;
use React\Http\Browser;
use React\Http\Message\Response;
use React\Promise;
use RuntimeException;

/**
 * Tests that HtmlFetcher re-validates redirect targets against the SSRF blocklist.
 *
 * Ensures that a redirect from a safe public URL to a private/metadata IP
 * is blocked at each hop, not just at the initial URL.
 */
class HtmlFetcherRedirectSsrfTest extends TestCase
{
    private function createValidator(): UrlValidator
    {
        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(function (string $host) {
            return match ($host) {
                'safe.example.com' => Promise\resolve('93.184.216.34'),
                'evil-redirect.test' => Promise\resolve('93.184.216.35'),
                'internal.test' => Promise\resolve('169.254.169.254'),
                default => Promise\reject(new Exception('DNS failed')),
            };
        });
        return new UrlValidator($resolver);
    }

    public function testRedirectToPrivateIpIsBlocked(): void
    {
        $validator = $this->createValidator();

        $browser = $this->createMock(Browser::class);
        $browser->method('withTimeout')->willReturnSelf();
        $browser->method('withFollowRedirects')->willReturnSelf();
        $browser->method('withHeader')->willReturnSelf();

        $callCount = 0;
        $browser->method('get')->willReturnCallback(function (string $url) use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Promise\resolve(new Response(302, ['Location' => 'http://127.0.0.1/admin']));
            }
            return Promise\resolve(new Response(200, [], 'OK'));
        });

        $fetcher = new HtmlFetcher($browser, $validator);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/private network/');
        $fetcher->fetch('https://safe.example.com');
    }

    public function testRedirectToMetadataEndpointIsBlocked(): void
    {
        $validator = $this->createValidator();

        $browser = $this->createMock(Browser::class);
        $browser->method('withTimeout')->willReturnSelf();
        $browser->method('withFollowRedirects')->willReturnSelf();
        $browser->method('withHeader')->willReturnSelf();

        $browser->method('get')->willReturnCallback(function () {
            return Promise\resolve(new Response(302, ['Location' => 'http://169.254.169.254/latest/meta-data/']));
        });

        $fetcher = new HtmlFetcher($browser, $validator);

        $this->expectException(RuntimeException::class);
        $fetcher->fetch('https://safe.example.com');
    }

    public function testRedirectToInternalHostnameIsBlocked(): void
    {
        $validator = $this->createValidator();

        $browser = $this->createMock(Browser::class);
        $browser->method('withTimeout')->willReturnSelf();
        $browser->method('withFollowRedirects')->willReturnSelf();
        $browser->method('withHeader')->willReturnSelf();

        $browser->method('get')->willReturnCallback(function () {
            return Promise\resolve(new Response(302, ['Location' => 'http://internal.test/secret']));
        });

        $fetcher = new HtmlFetcher($browser, $validator);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/private network/');
        $fetcher->fetch('https://safe.example.com');
    }

    public function testSafeRedirectIsAllowed(): void
    {
        $validator = $this->createValidator();

        $browser = $this->createMock(Browser::class);
        $browser->method('withTimeout')->willReturnSelf();
        $browser->method('withFollowRedirects')->willReturnSelf();
        $browser->method('withHeader')->willReturnSelf();

        $callCount = 0;
        $browser->method('get')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Promise\resolve(new Response(302, ['Location' => 'https://safe.example.com/final']));
            }
            return Promise\resolve(new Response(200, ['Content-Type' => 'text/html'], '<h1>OK</h1>'));
        });

        $fetcher = new HtmlFetcher($browser, $validator);
        $result = $fetcher->fetch('https://safe.example.com');

        $this->assertEquals(200, $result['status']);
        $this->assertStringContainsString('OK', $result['html']);
    }

    public function testFetcherWithoutValidatorAllowsAllRedirects(): void
    {
        $browser = $this->createMock(Browser::class);
        $browser->method('withTimeout')->willReturnSelf();
        $browser->method('withFollowRedirects')->willReturnSelf();
        $browser->method('withHeader')->willReturnSelf();

        $callCount = 0;
        $browser->method('get')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Promise\resolve(new Response(302, ['Location' => 'http://127.0.0.1/admin']));
            }
            return Promise\resolve(new Response(200, ['Content-Type' => 'text/html'], 'secret'));
        });

        $fetcher = new HtmlFetcher($browser);
        $result = $fetcher->fetch('https://example.com');

        $this->assertEquals(200, $result['status']);
        $this->assertEquals('secret', $result['html']);
    }
}

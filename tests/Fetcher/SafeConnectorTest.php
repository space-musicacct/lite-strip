<?php

declare(strict_types=1);

namespace LiteStrip\Tests\Fetcher;

use LiteStrip\Fetcher\SafeConnector;
use PHPUnit\Framework\TestCase;
use React\Dns\Model\Message;
use React\Dns\Resolver\ResolverInterface;
use React\Socket\ConnectorInterface;
use RuntimeException;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Verifies that SafeConnector dials only IPs that pass the blocklist, so the
 * address checked is always the address connected to. Uses a recording base
 * connector (never opens a real socket) and a fake resolver with scripted
 * A records; react/promise settles synchronously, so assertions run inline.
 */
class SafeConnectorTest extends TestCase
{
    /**
     * @param list<string> $aRecords A records the fake resolver returns for any host
     * @return array{0: list<string>, 1: bool} Dialed URIs and whether the connect promise rejected
     */
    private function attempt(array $aRecords, string $uri): array
    {
        $recorder = new class implements ConnectorInterface {
            /** @var list<string> */
            public array $dialed = [];

            public function connect($uri)
            {
                $this->dialed[] = $uri;

                return reject(new RuntimeException('recorder: no real connection'));
            }
        };

        $resolver = new class ($aRecords) implements ResolverInterface {
            /** @param list<string> $a */
            public function __construct(private array $a) {}

            public function resolve($domain)
            {
                return resolve($this->a[array_rand($this->a)]);
            }

            public function resolveAll($domain, $type)
            {
                return resolve($type === Message::TYPE_A ? $this->a : []);
            }
        };

        $rejected = false;
        (new SafeConnector($resolver, $recorder))
            ->connect($uri)
            ->then(null, function () use (&$rejected): void {
                $rejected = true;
            });

        return [$recorder->dialed, $rejected];
    }

    public function testFiltersInternalIpFromMixedRecordSet(): void
    {
        [$dialed] = $this->attempt(['8.8.8.8', '127.0.0.1'], 'tcp://mixed.test:80');

        $this->assertCount(1, $dialed);
        $this->assertStringContainsString('8.8.8.8', $dialed[0]);
        $this->assertStringNotContainsString('127.0.0.1', $dialed[0]);
    }

    public function testPreservesHostnameForSni(): void
    {
        [$dialed] = $this->attempt(['8.8.8.8'], 'tls://api.test:443');

        $this->assertStringStartsWith('tls://8.8.8.8:443', $dialed[0]);
        $this->assertStringContainsString('hostname=api.test', $dialed[0]);
    }

    public function testRejectsWhenEveryRecordIsBlocked(): void
    {
        [$dialed, $rejected] = $this->attempt(['127.0.0.1'], 'tcp://internal.test:80');

        $this->assertTrue($rejected);
        $this->assertSame([], $dialed);
    }

    public function testRejectsHostResolvingToDockerNetwork(): void
    {
        [$dialed, $rejected] = $this->attempt(['172.19.0.8'], 'tcp://db.test:3306');

        $this->assertTrue($rejected);
        $this->assertSame([], $dialed);
    }

    public function testRejectsLoopbackIpLiteralWithoutDialing(): void
    {
        [$dialed, $rejected] = $this->attempt(['unused'], 'tcp://127.0.0.1:80');

        $this->assertTrue($rejected);
        $this->assertSame([], $dialed);
    }

    public function testRejectsIpv4MappedIpv6Literal(): void
    {
        [$dialed, $rejected] = $this->attempt(['unused'], 'tcp://[::ffff:127.0.0.1]:80');

        $this->assertTrue($rejected);
        $this->assertSame([], $dialed);
    }

    public function testPassesPublicIpLiteralThroughUnchanged(): void
    {
        [$dialed] = $this->attempt(['unused'], 'tcp://8.8.8.8:80');

        $this->assertSame(['tcp://8.8.8.8:80'], $dialed);
    }
}

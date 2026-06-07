<?php

declare(strict_types=1);

namespace LiteStrip\Tests\Server;

use DateInvalidTimeZoneException;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests timezone validation logic used by RequestHandler's parseOptions.
 * Verifies that DateTimeZone correctly accepts/rejects IANA timezone strings.
 */
class TimezoneValidationTest extends TestCase
{
    #[DataProvider('validTimezoneProvider')]
    public function testAcceptsValidTimezone(string $tz): void
    {
        $dateTimeZone = new DateTimeZone($tz);
        $dt = new DateTimeImmutable('now', $dateTimeZone);
        $this->assertNotEmpty($dt->format('c'));
    }

    public static function validTimezoneProvider(): array
    {
        return [
            'UTC' => ['UTC'],
            'Asia/Tokyo' => ['Asia/Tokyo'],
            'America/New_York' => ['America/New_York'],
            'Europe/London' => ['Europe/London'],
            'Pacific/Auckland' => ['Pacific/Auckland'],
            'America/Los_Angeles' => ['America/Los_Angeles'],
            'Asia/Shanghai' => ['Asia/Shanghai'],
            'Europe/Berlin' => ['Europe/Berlin'],
        ];
    }

    #[DataProvider('invalidTimezoneProvider')]
    public function testRejectsInvalidTimezone(string $tz): void
    {
        $this->expectException(DateInvalidTimeZoneException::class);
        new DateTimeZone($tz);
    }

    public static function invalidTimezoneProvider(): array
    {
        return [
            'empty string' => [''],
            'random string' => ['NotATimezone'],
            'partial' => ['Asia/'],
            'numeric' => ['12345'],
            'SQL injection attempt' => ["'; DROP TABLE users; --"],
            'XSS attempt' => ['<script>alert(1)</script>'],
        ];
    }

    public function testTimezoneAffectsOutput(): void
    {
        $utc = new DateTimeImmutable('2026-06-08 00:00:00', new DateTimeZone('UTC'));
        $tokyo = $utc->setTimezone(new DateTimeZone('Asia/Tokyo'));

        $this->assertEquals('2026-06-08T00:00:00+00:00', $utc->format('c'));
        $this->assertEquals('2026-06-08T09:00:00+09:00', $tokyo->format('c'));
    }

    public function testNullTimezoneFallsBackToDefault(): void
    {
        $dt = new DateTimeImmutable('now');
        $this->assertNotEmpty($dt->format('c'));
    }
}

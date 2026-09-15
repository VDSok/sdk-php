<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vdsok\Sdk\Timestamp;

final class TimestampTest extends TestCase
{
    public function testParsesPlainUtc(): void
    {
        $dt = Timestamp::parse('2026-09-15T10:00:00Z');
        self::assertSame('2026-09-15 10:00:00.000000', $dt->format('Y-m-d H:i:s.u'));
        self::assertSame('UTC', $dt->getTimezone()->getName());
        self::assertSame(gmmktime(10, 0, 0, 9, 15, 2026), $dt->getTimestamp());
    }

    public function testParsesFractionsAndTruncatesBeyondMicroseconds(): void
    {
        self::assertSame('123000', Timestamp::parse('2026-09-15T10:00:00.123Z')->format('u'));
        self::assertSame('123456', Timestamp::parse('2026-09-15T10:00:00.123456789Z')->format('u'));
    }

    public static function invalid(): iterable
    {
        yield 'offset' => ['2026-09-15T10:00:00+03:00'];
        yield 'no zone' => ['2026-09-15T10:00:00'];
        yield 'space' => ['2026-09-15 10:00:00Z'];
        yield 'date only' => ['2026-09-15'];
        yield 'garbage' => ['yesterday'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalid')]
    public function testRejectsAnythingElse(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Timestamp::parse($value);
    }

    public function testRejectsImpossibleDate(): void
    {
        // Регулярное выражение пропускает 13-й месяц; createFromFormat должен его отбросить.
        $this->expectException(\InvalidArgumentException::class);
        Timestamp::parse('2026-13-45T10:00:00Z');
    }

    public function testNullable(): void
    {
        self::assertNull(Timestamp::parseNullable(null));
        self::assertNotNull(Timestamp::parseNullable('2026-09-15T10:00:00Z'));
    }

    public function testFormatConvertsToUtcAndKeepsNonZeroMicros(): void
    {
        $local = new \DateTimeImmutable('2026-09-15 13:00:00', new \DateTimeZone('+03:00'));
        self::assertSame('2026-09-15T10:00:00Z', Timestamp::format($local));

        $withMicros = new \DateTimeImmutable('2026-09-15 10:00:00.250000', new \DateTimeZone('UTC'));
        self::assertSame('2026-09-15T10:00:00.25Z', Timestamp::format($withMicros));

        $mutable = new \DateTime('2026-09-15 10:00:00', new \DateTimeZone('UTC'));
        self::assertSame('2026-09-15T10:00:00Z', Timestamp::format($mutable));
    }

    public function testRoundTrip(): void
    {
        $s = '2026-09-15T10:00:00Z';
        self::assertSame($s, Timestamp::format(Timestamp::parse($s)));
    }
}

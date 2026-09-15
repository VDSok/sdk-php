<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Tests;

use PHPUnit\Framework\TestCase;
use Vdsok\Sdk\RateLimitInfo;

final class RateLimitTest extends TestCase
{
    public function testParsesHeaders(): void
    {
        $info = RateLimitInfo::fromHeaders([
            'x-ratelimit-limit' => '120',
            'x-ratelimit-remaining' => '0',
            'x-ratelimit-reset' => '1789466400',
        ]);

        self::assertNotNull($info);
        self::assertSame(120, $info->limit);
        self::assertSame(0, $info->remaining);
        self::assertSame(1789466400, $info->reset);
        self::assertSame('2026-09-15T10:00:00+00:00', $info->resetAt()?->format(DATE_ATOM));
        self::assertSame(60, $info->secondsUntilReset(1789466340));
        self::assertSame(0, $info->secondsUntilReset(1789466500));
    }

    public function testAbsentHeadersGiveNull(): void
    {
        self::assertNull(RateLimitInfo::fromHeaders([]));
        self::assertNull(RateLimitInfo::fromHeaders(['content-type' => 'application/json']));
    }

    public function testPartialAndMalformedValues(): void
    {
        $info = RateLimitInfo::fromHeaders(['x-ratelimit-limit' => '20', 'x-ratelimit-reset' => 'soon']);
        self::assertNotNull($info);
        self::assertSame(20, $info->limit);
        self::assertNull($info->remaining);
        self::assertNull($info->reset);
        self::assertNull($info->resetAt());
        self::assertNull($info->secondsUntilReset());
    }
}

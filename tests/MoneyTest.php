<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Tests;

use PHPUnit\Framework\TestCase;
use Vdsok\Sdk\Money;

final class MoneyTest extends TestCase
{
    public function testIsValid(): void
    {
        self::assertTrue(Money::isValid('5.90'));
        self::assertTrue(Money::isValid('0.0083'));
        self::assertTrue(Money::isValid('-12.00'));
        self::assertFalse(Money::isValid('5.9'));
        self::assertFalse(Money::isValid('5'));
        self::assertFalse(Money::isValid('5.12345'));
        self::assertFalse(Money::isValid('5,90'));
    }

    public function testFormat(): void
    {
        self::assertSame('25.00', Money::format(25));
        self::assertSame('25.00', Money::format('25'));
        self::assertSame('25.50', Money::format('25.5'));
        self::assertSame('25.50', Money::format('25.50'));
        self::assertSame('0.0083', Money::format('0.0083'));
        self::assertSame('-12.00', Money::format('-12'));
        self::assertSame('25.50', Money::format(25.5));
        self::assertSame('0.0083', Money::format(0.0083, 4));
        self::assertSame('1.00', Money::format(1, 2));
    }

    public function testFormatRejectsGarbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::format('25,00');
    }

    public function testFormatRejectsInfinity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::format(INF);
    }

    public function testMinorUnits(): void
    {
        self::assertSame('25.00', Money::fromMinor(2500));
        self::assertSame('0.05', Money::fromMinor(5));
        self::assertSame('-1.80', Money::fromMinor(-180));
        self::assertSame('0.0083', Money::fromMinor(83, 4));
        self::assertSame(2500, Money::toMinor('25.00'));
        self::assertSame(-180, Money::toMinor('-1.80'));
        self::assertSame(83, Money::toMinor('0.0083', 4));
        self::assertSame(590, Money::toMinor('5.9000'));
    }

    public function testToMinorRejectsPrecisionLoss(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::toMinor('0.0083');
    }

    public function testCompare(): void
    {
        self::assertSame(0, Money::compare('5.90', '5.9000'));
        self::assertSame(-1, Money::compare('4.10', '5.90'));
        self::assertSame(1, Money::compare('0.0084', '0.0083'));
        self::assertSame(1, Money::compare('0.00', '-0.01'));
    }

    /**
     * Регрессия: `compare()` шла напрямую в `toMinor()`, который требует
     * точку, и падала на том, что `format()` спокойно принимает. Типичный
     * случай — сравнить сумму из API с порогом из конфига («100»).
     */
    public function testCompareAcceptsWhatFormatAccepts(): void
    {
        self::assertSame(1, Money::compare('25', '5.90'));
        self::assertSame(-1, Money::compare('25', '30'));
        self::assertSame(0, Money::compare('25', '25.00'));
        self::assertSame(-1, Money::compare('-12', '0.00'));
        self::assertSame(0, Money::compare('25.5', '25.50'));
    }

    public function testCompareStillRejectsGarbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::compare('25,00', '25.00');
    }
}

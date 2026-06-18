<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\MoneyMismatchException;
use App\Shared\Domain\ValueObject\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testCreatesPositiveMinorAmountAndUppercasesCurrency(): void
    {
        $money = Money::fromMinor(12345, 'rub');

        self::assertSame(12345, $money->amountMinor());
        self::assertSame('RUB', $money->currency());
        self::assertFalse($money->isZero());
    }

    public function testCreatesZeroMinorAmount(): void
    {
        $money = Money::fromMinor(0, 'RUB');

        self::assertSame(0, $money->amountMinor());
        self::assertSame('RUB', $money->currency());
        self::assertTrue($money->isZero());
    }

    public function testCreatesNegativeMinorAmount(): void
    {
        $money = Money::fromMinor(-12345, 'RUB');

        self::assertSame(-12345, $money->amountMinor());
        self::assertSame('RUB', $money->currency());
        self::assertFalse($money->isZero());
    }

    public function testRejectsInvalidCurrency(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::fromMinor(100, 'RU');
    }

    public function testAddsSameCurrency(): void
    {
        $money = Money::fromMinor(1000, 'RUB');

        self::assertSame(1250, $money->add(Money::fromMinor(250, 'RUB'))->amountMinor());
    }

    public function testSubtractsSameCurrencyWithPositiveResult(): void
    {
        $money = Money::fromMinor(1000, 'RUB');

        self::assertSame(750, $money->subtract(Money::fromMinor(250, 'RUB'))->amountMinor());
    }

    public function testSubtractsSameCurrencyWithNegativeResult(): void
    {
        $money = Money::fromMinor(250, 'RUB');

        self::assertSame(-750, $money->subtract(Money::fromMinor(1000, 'RUB'))->amountMinor());
    }

    public function testNegatesAmount(): void
    {
        self::assertSame(-1000, Money::fromMinor(1000, 'RUB')->negate()->amountMinor());
        self::assertSame(1000, Money::fromMinor(-1000, 'RUB')->negate()->amountMinor());
        self::assertSame(0, Money::fromMinor(0, 'RUB')->negate()->amountMinor());
    }

    public function testComparesSameCurrency(): void
    {
        self::assertSame(1, Money::fromMinor(1000, 'RUB')->compareTo(Money::fromMinor(999, 'RUB')));
        self::assertSame(0, Money::fromMinor(1000, 'RUB')->compareTo(Money::fromMinor(1000, 'RUB')));
        self::assertSame(-1, Money::fromMinor(999, 'RUB')->compareTo(Money::fromMinor(1000, 'RUB')));
    }

    public function testRejectsAddCurrencyMismatch(): void
    {
        $this->expectException(MoneyMismatchException::class);

        Money::fromMinor(1000, 'RUB')->add(Money::fromMinor(100, 'USD'));
    }

    public function testRejectsSubtractCurrencyMismatch(): void
    {
        $this->expectException(MoneyMismatchException::class);

        Money::fromMinor(1000, 'RUB')->subtract(Money::fromMinor(100, 'USD'));
    }

    public function testRejectsCompareCurrencyMismatch(): void
    {
        $this->expectException(MoneyMismatchException::class);

        Money::fromMinor(1000, 'RUB')->compareTo(Money::fromMinor(100, 'USD'));
    }
}

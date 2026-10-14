<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\MoneyMismatchException;
use App\Shared\Domain\Exception\MoneyOverflowException;
use App\Shared\Domain\ValueObject\Money;
use App\Shared\Domain\ValueObject\RoundingMode;
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

    public function testFromStringParsesDecimalForTwoFractionCurrency(): void
    {
        self::assertSame(12345, Money::fromString('123.45', 'rub')->amountMinor());
        self::assertSame('RUB', Money::fromString('123.45', 'rub')->currency());
    }

    public function testFromStringNormalizesSpacesAndComma(): void
    {
        self::assertSame(123456, Money::fromString('1 234,56', 'RUB')->amountMinor());
        self::assertSame(123456, Money::fromString("1\u{00A0}234.56", 'RUB')->amountMinor());
    }

    public function testFromStringParsesNegative(): void
    {
        self::assertSame(-12345, Money::fromString('-123.45', 'RUB')->amountMinor());
    }

    public function testFromStringUsesCurrencyFractionDigits(): void
    {
        self::assertSame(1000, Money::fromString('1000', 'JPY')->amountMinor());
        self::assertSame(1000, Money::fromString('1000.4', 'JPY')->amountMinor());
    }

    public function testFromStringRoundsHalfUp(): void
    {
        self::assertSame(12346, Money::fromString('123.455', 'RUB')->amountMinor());
        self::assertSame(12345, Money::fromString('123.454', 'RUB')->amountMinor());
    }

    public function testFromStringRejectsGarbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::fromString('abc', 'RUB');
    }

    public function testToDecimalString(): void
    {
        self::assertSame('123.45', Money::fromMinor(12345, 'RUB')->toDecimalString());
        self::assertSame('-123.45', Money::fromMinor(-12345, 'RUB')->toDecimalString());
        self::assertSame('0.05', Money::fromMinor(5, 'RUB')->toDecimalString());
        self::assertSame('1000', Money::fromMinor(1000, 'JPY')->toDecimalString());
    }

    public function testRoundTripStringConversion(): void
    {
        self::assertSame('123.45', Money::fromString('123.45', 'RUB')->toDecimalString());
    }

    public function testMultiply(): void
    {
        self::assertSame(1500, Money::fromMinor(1000, 'RUB')->multiply('1.5')->amountMinor());
        self::assertSame(100, Money::fromMinor(1000, 'RUB')->multiply('0.1')->amountMinor());
    }

    public function testMultiplyRoundingModes(): void
    {
        self::assertSame(101, Money::fromMinor(100, 'RUB')->multiply('1.005', RoundingMode::HALF_UP)->amountMinor());
        self::assertSame(100, Money::fromMinor(100, 'RUB')->multiply('1.005', RoundingMode::HALF_EVEN)->amountMinor());
    }

    public function testPercentage(): void
    {
        self::assertSame(2000, Money::fromMinor(10000, 'RUB')->percentage('20')->amountMinor());
        self::assertSame(1, Money::fromMinor(100, 'RUB')->percentage('0.5', RoundingMode::HALF_UP)->amountMinor());
        self::assertSame(0, Money::fromMinor(100, 'RUB')->percentage('0.5', RoundingMode::HALF_EVEN)->amountMinor());
    }

    public function testAbs(): void
    {
        self::assertSame(12345, Money::fromMinor(-12345, 'RUB')->abs()->amountMinor());
        self::assertSame(12345, Money::fromMinor(12345, 'RUB')->abs()->amountMinor());
    }

    public function testAbsRejectsIntMinOverflow(): void
    {
        $this->expectException(MoneyOverflowException::class);

        Money::fromMinor(\PHP_INT_MIN, 'RUB')->abs();
    }

    public function testFromStringRejectsOverflow(): void
    {
        $this->expectException(MoneyOverflowException::class);

        // 22 цифры после умножения на 100 — заведомо больше PHP_INT_MAX (~9.2e18)
        Money::fromString('99999999999999999999', 'RUB');
    }

    public function testMultiplyRejectsOverflow(): void
    {
        $this->expectException(MoneyOverflowException::class);

        Money::fromMinor(\PHP_INT_MAX, 'RUB')->multiply('2');
    }

    public function testMultiplyRejectsNegativeOverflow(): void
    {
        $this->expectException(MoneyOverflowException::class);

        Money::fromMinor(\PHP_INT_MAX, 'RUB')->multiply('-2');
    }

    public function testPercentageRejectsOverflow(): void
    {
        $this->expectException(MoneyOverflowException::class);

        Money::fromMinor(\PHP_INT_MAX, 'RUB')->percentage('1000');
    }

    public function testMultiplyRejectsNonNumericFactor(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::fromMinor(1000, 'RUB')->multiply('abc');
    }

    public function testPercentageRejectsNonNumericPercent(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::fromMinor(1000, 'RUB')->percentage('20%');
    }

    public function testArithmeticStaysWithinRangeDoesNotThrow(): void
    {
        self::assertSame(\PHP_INT_MAX, Money::fromMinor(\PHP_INT_MAX, 'RUB')->multiply('1')->amountMinor());
    }

    public function testPredicates(): void
    {
        self::assertTrue(Money::fromMinor(1, 'RUB')->isPositive());
        self::assertFalse(Money::fromMinor(0, 'RUB')->isPositive());
        self::assertTrue(Money::fromMinor(-1, 'RUB')->isNegative());
        self::assertFalse(Money::fromMinor(0, 'RUB')->isNegative());
    }

    public function testEquals(): void
    {
        self::assertTrue(Money::fromMinor(100, 'RUB')->equals(Money::fromMinor(100, 'RUB')));
        self::assertFalse(Money::fromMinor(100, 'RUB')->equals(Money::fromMinor(101, 'RUB')));
        self::assertFalse(Money::fromMinor(100, 'RUB')->equals(Money::fromMinor(100, 'USD')));
    }

    public function testMultiplyByRatioIsExactForTerminatingResult(): void
    {
        // НДС 5% «из суммы» 105.00 — ровно 5.00.
        self::assertSame(500, Money::fromString('105.00', 'RUB')->multiplyByRatio('5', '105')->amountMinor());
    }

    public function testMultiplyByRatioRoundsOnceAtTheEnd(): void
    {
        // 100.00 × 20/120 = 16.6666… → 16.67.
        self::assertSame(1667, Money::fromString('100.00', 'RUB')->multiplyByRatio('20', '120')->amountMinor());
    }

    public function testMultiplyByRatioAppliesRoundingModeAtExactHalf(): void
    {
        $oneKopeck = Money::fromMinor(1, 'RUB');

        self::assertSame(1, $oneKopeck->multiplyByRatio('1', '2')->amountMinor());
        self::assertSame(0, $oneKopeck->multiplyByRatio('1', '2', RoundingMode::HALF_EVEN)->amountMinor());
        self::assertSame(-1, $oneKopeck->negate()->multiplyByRatio('1', '2')->amountMinor());
    }

    public function testMultiplyByRatioKeepsPrecisionForLargeAmounts(): void
    {
        // 999 999 999.99 × 5/105 = 47 619 047.618… → 47 619 047.62.
        $vat = Money::fromString('999999999.99', 'RUB')->multiplyByRatio('5', '105');

        self::assertSame('47619047.62', $vat->toDecimalString());
        self::assertSame('RUB', $vat->currency());
    }

    public function testMultiplyByRatioRejectsZeroDenominator(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::fromMinor(100, 'RUB')->multiplyByRatio('1', '0');
    }

    public function testMultiplyByRatioRoundsNegativeHalfToEven(): void
    {
        // −3 × 1/2 = −1.5 → −2 (к чётному), −5 × 1/2 = −2.5 → −2.
        self::assertSame(-2, Money::fromMinor(-3, 'RUB')->multiplyByRatio('1', '2', RoundingMode::HALF_EVEN)->amountMinor());
        self::assertSame(-2, Money::fromMinor(-5, 'RUB')->multiplyByRatio('1', '2', RoundingMode::HALF_EVEN)->amountMinor());
        self::assertSame(-3, Money::fromMinor(-5, 'RUB')->multiplyByRatio('1', '2')->amountMinor());
    }

    public function testMultiplyByRatioUsesCurrencyFractionDigits(): void
    {
        // JPY без дробной части: 1000 × 5/105 = 47.6… → 48.
        self::assertSame('48', Money::fromString('1000', 'JPY')->multiplyByRatio('5', '105')->toDecimalString());
        // BHD — три знака: 1.050 × 5/105 = 0.050.
        self::assertSame('0.050', Money::fromString('1.050', 'BHD')->multiplyByRatio('5', '105')->toDecimalString());
    }

    public function testMultiplyByRatioDetectsOverflow(): void
    {
        $this->expectException(MoneyOverflowException::class);

        Money::fromMinor(\PHP_INT_MAX, 'RUB')->multiplyByRatio('2', '1');
    }

    public function testMultiplyByRatioRejectsArgumentsBeyondTwelveFractionalDigits(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::fromMinor(100, 'RUB')->multiplyByRatio('1', '0.0000000000001');
    }
}

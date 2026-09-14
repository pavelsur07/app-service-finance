<?php

declare(strict_types=1);

namespace App\Tests\Unit\Balance;

use App\Balance\Domain\Policy\LedgerAmount;
use App\Balance\Exception\BalanceLedgerException;
use PHPUnit\Framework\TestCase;

final class LedgerAmountTest extends TestCase
{
    public function testExactCurrencyPrecision(): void
    {
        self::assertSame('12345', LedgerAmount::minor('123.45', 'RUB'));
        self::assertSame('123', LedgerAmount::minor('123', 'JPY'));
        self::assertSame('1234', LedgerAmount::minor('1.234', 'BHD'));
    }

    public function testMinorAmountsFormatWithoutFloat(): void
    {
        self::assertSame('92233720368547758.07', LedgerAmount::decimal('9223372036854775807', 'RUB'));
        self::assertSame('-12.345', LedgerAmount::decimal('-12345', 'BHD'));
        self::assertSame('100', LedgerAmount::decimal('100', 'JPY'));
    }

    public function testExcessPrecisionIsRejectedInsteadOfRounded(): void
    {
        $this->expectException(BalanceLedgerException::class);
        LedgerAmount::minor('1.001', 'RUB');
    }

    public function testOverflowIsRejected(): void
    {
        $this->expectException(BalanceLedgerException::class);
        LedgerAmount::minor('92233720368547758.08', 'RUB');
    }

    public function testInvalidCalendarDateIsRejected(): void
    {
        $this->expectException(BalanceLedgerException::class);
        LedgerAmount::date('2026-02-30');
    }
}

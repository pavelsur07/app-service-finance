<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash;

use App\Cash\DTO\CashTransactionFilters;
use PHPUnit\Framework\TestCase;

final class CashTransactionFiltersTest extends TestCase
{
    public function testParsesCurrencyAndPreservesEmptyDefault(): void
    {
        self::assertSame('USD', CashTransactionFilters::fromQuery(['currency' => 'USD'])['currency']);
        self::assertSame('EUR', CashTransactionFilters::fromQuery(['currency' => ' eur '])['currency']);
        self::assertNull(CashTransactionFilters::fromQuery([])['currency']);
        self::assertNull(CashTransactionFilters::fromQuery(['currency' => ''])['currency']);
    }

    public function testRejectsUnsupportedCurrency(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CashTransactionFilters::fromQuery(['currency' => 'BTC']);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Enum;

use App\Cash\Enum\FiatCurrency;
use PHPUnit\Framework\TestCase;

final class FiatCurrencyTest extends TestCase
{
    public function testProvidesSupportedFiatCodes(): void
    {
        self::assertSame(['RUB', 'USD', 'EUR', 'KZT'], FiatCurrency::values());
    }

    public function testNormalizesSupportedCode(): void
    {
        self::assertSame(FiatCurrency::USD, FiatCurrency::fromCode(' usd '));
    }

    public function testRejectsUnsupportedCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported fiat currency: USDT.');

        FiatCurrency::fromCode('usdt');
    }

    public function testFiatCurrenciesUseTwoDecimalPlaces(): void
    {
        foreach (FiatCurrency::cases() as $currency) {
            self::assertSame(2, $currency->scale());
        }
    }

    public function testTransferPairsAreLimitedToSameCurrencyAndRubWithUsdOrEur(): void
    {
        self::assertTrue(FiatCurrency::RUB->canTransferTo(FiatCurrency::RUB));
        self::assertTrue(FiatCurrency::RUB->canTransferTo(FiatCurrency::USD));
        self::assertTrue(FiatCurrency::EUR->canTransferTo(FiatCurrency::RUB));
        self::assertFalse(FiatCurrency::USD->canTransferTo(FiatCurrency::EUR));
        self::assertFalse(FiatCurrency::KZT->canTransferTo(FiatCurrency::RUB));
    }
}

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
}

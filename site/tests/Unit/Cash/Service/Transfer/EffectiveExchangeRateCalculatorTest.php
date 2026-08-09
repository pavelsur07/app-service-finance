<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Service\Transfer;

use App\Cash\Entity\Transfer\CashTransfer;
use App\Cash\Enum\FiatCurrency;
use App\Cash\Service\Transfer\EffectiveExchangeRateCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EffectiveExchangeRateCalculatorTest extends TestCase
{
    #[DataProvider('crossCurrencyProvider')]
    public function testCalculatesTargetCurrencyPerOneSourceCurrency(
        string $sourceAmount,
        string $sourceCurrency,
        string $targetAmount,
        string $targetCurrency,
        string $expectedRate,
    ): void {
        $date = new \DateTimeImmutable('2026-08-09');

        $rate = (new EffectiveExchangeRateCalculator())->calculate(
            $sourceAmount,
            $sourceCurrency,
            $targetAmount,
            $targetCurrency,
            $date,
        );

        self::assertNotNull($rate);
        self::assertSame($expectedRate, $rate->value());
        self::assertSame(FiatCurrency::from($sourceCurrency), $rate->baseCurrency());
        self::assertSame(FiatCurrency::from($targetCurrency), $rate->quoteCurrency());
        self::assertSame($date, $rate->date());
        self::assertSame(CashTransfer::RATE_SOURCE_MANUAL_EFFECTIVE, $rate->source());
    }

    /**
     * @return iterable<string, array{string, string, string, string, string}>
     */
    public static function crossCurrencyProvider(): iterable
    {
        yield 'RUB to USD' => ['9500.00', 'RUB', '100.00', 'USD', '0.010526315789473684'];
        yield 'USD to RUB' => ['100.00', 'USD', '9500.00', 'RUB', '95.000000000000000000'];
        yield 'half-up at scale 18' => ['0.06', 'RUB', '0.01', 'EUR', '0.166666666666666667'];
    }

    public function testSameCurrencyEqualAmountsDoNotProduceFxMetadata(): void
    {
        self::assertNull((new EffectiveExchangeRateCalculator())->calculate(
            '100.00',
            'RUB',
            '100.00',
            'RUB',
            new \DateTimeImmutable('2026-08-09'),
        ));
    }

    public function testRejectsDifferentAmountsInSameCurrency(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Суммы перевода в одной валюте должны совпадать.');

        (new EffectiveExchangeRateCalculator())->calculate(
            '100.00',
            'RUB',
            '99.99',
            'RUB',
            new \DateTimeImmutable('2026-08-09'),
        );
    }

    public function testRejectsUnsupportedCrossCurrencyPair(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Разрешены только кросс-валютные переводы RUB↔USD и RUB↔EUR.');

        (new EffectiveExchangeRateCalculator())->calculate(
            '100.00',
            'USD',
            '90.00',
            'EUR',
            new \DateTimeImmutable('2026-08-09'),
        );
    }

    public function testRejectsAmountOutsideCurrencyScale(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Сумма перевода должна иметь не более 2 знаков после запятой.');

        (new EffectiveExchangeRateCalculator())->calculate(
            '100.001',
            'RUB',
            '1.00',
            'USD',
            new \DateTimeImmutable('2026-08-09'),
        );
    }
}

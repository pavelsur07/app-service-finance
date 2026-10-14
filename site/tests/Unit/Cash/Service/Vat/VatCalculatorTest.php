<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Service\Vat;

use App\Cash\Service\Vat\VatCalculator;
use App\Shared\Domain\ValueObject\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VatCalculatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, string, string}>
     */
    public static function splitProvider(): iterable
    {
        yield '5% из 105.00' => ['105.00', 5, '5.00', '100.00'];
        yield '7% из 1070.00' => ['1070.00', 7, '70.00', '1000.00'];
        yield '20% из 120.00' => ['120.00', 20, '20.00', '100.00'];
        // Ровно полкопейки: 0.09 × 20/120 = 0.015. float хранит 0.01499… и
        // number_format() давал 0.01; правило — половина от нуля, 0.02.
        yield '20%, ровно полкопейки' => ['0.09', 20, '0.02', '0.07'];
        yield '20%, ещё полкопейки' => ['2.01', 20, '0.34', '1.67'];
        yield 'большая сумма' => ['999999999.99', 5, '47619047.62', '952380952.37'];
        yield 'без НДС' => ['100.00', 0, '0.00', '100.00'];
    }

    #[DataProvider('splitProvider')]
    public function testSplitsGrossIntoNetAndVat(string $gross, int $rate, string $vat, string $net): void
    {
        $split = (new VatCalculator())->splitGross(Money::fromString($gross, 'RUB'), $rate);

        self::assertSame($vat, $split['vat']->toDecimalString());
        self::assertSame($net, $split['net']->toDecimalString());
        self::assertTrue($split['net']->add($split['vat'])->equals(Money::fromString($gross, 'RUB')));
    }

    public function testKeepsCurrency(): void
    {
        $split = (new VatCalculator())->splitGross(Money::fromString('120.00', 'USD'), 20);

        self::assertSame('USD', $split['vat']->currency());
        self::assertSame('USD', $split['net']->currency());
    }
}

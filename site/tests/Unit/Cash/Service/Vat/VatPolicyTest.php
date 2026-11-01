<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Service\Vat;

use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Service\Vat\VatPolicy;
use App\Cash\Service\Vat\VatRates;
use App\Company\Enum\CompanyTaxSystem;
use App\Tests\Builders\Company\CompanyBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VatPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{?CompanyTaxSystem, CashDirection, string, ?int}>
     */
    public static function decisionProvider(): iterable
    {
        yield 'режим не выбран' => [null, CashDirection::OUTFLOW, '2026-03-10', null];
        yield 'УСН без НДС' => [CompanyTaxSystem::USN_NO_VAT, CashDirection::OUTFLOW, '2026-03-10', null];
        yield 'УСН 5%, расход' => [CompanyTaxSystem::USN_VAT_5, CashDirection::OUTFLOW, '2026-03-10', 5];
        yield 'УСН 5%, поступление' => [CompanyTaxSystem::USN_VAT_5, CashDirection::INFLOW, '2026-03-10', null];
        yield 'УСН 7%, расход' => [CompanyTaxSystem::USN_VAT_7, CashDirection::OUTFLOW, '2026-03-10', 7];
        yield 'УСН 7%, поступление' => [CompanyTaxSystem::USN_VAT_7, CashDirection::INFLOW, '2026-03-10', null];
        yield 'ОСНО, расход 2025' => [CompanyTaxSystem::OSNO, CashDirection::OUTFLOW, '2025-12-15', 20];
        // Регрессия: с 01.01.2026 общая ставка 22%, раньше политика ставила 20% на любую дату.
        yield 'ОСНО, расход 2026' => [CompanyTaxSystem::OSNO, CashDirection::OUTFLOW, '2026-03-10', 22];
        yield 'ОСНО, поступление 2026' => [CompanyTaxSystem::OSNO, CashDirection::INFLOW, '2026-03-10', null];
    }

    #[DataProvider('decisionProvider')]
    public function testDecidesRateByTaxSystemDirectionAndDate(
        ?CompanyTaxSystem $taxSystem,
        CashDirection $direction,
        string $occurredOn,
        ?int $expectedRate,
    ): void {
        $company = CompanyBuilder::aCompany()->build();
        $company->setTaxSystem($taxSystem);

        $rate = (new VatPolicy(new VatRates()))->decideForCash($company, $direction, new \DateTimeImmutable($occurredOn));

        self::assertSame($expectedRate, $rate);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function generalRateProvider(): iterable
    {
        yield 'последняя секунда 2025' => ['2025-12-31 23:59:59', 20];
        yield 'первая секунда 2026' => ['2026-01-01 00:00:00', 22];
        // Граница — по календарной дате операции, а не по моменту в UTC:
        // полночь 01.01.2026 по Москве — это ещё 2025 год в UTC.
        yield 'полночь 2026 по Москве' => ['2026-01-01 00:00:00 Europe/Moscow', 22];
        yield '31.12.2025 вечером по Владивостоку' => ['2025-12-31 23:30:00 Asia/Vladivostok', 20];
        yield '2019' => ['2019-06-01', 20];
        yield 'до 2019 — 18%' => ['2018-12-31 23:59:59', 18];
        yield 'будущее' => ['2030-06-01', 22];
    }

    #[DataProvider('generalRateProvider')]
    public function testGeneralRateSwitchesOnCalendarDate(string $at, int $expected): void
    {
        self::assertSame($expected, (new VatRates())->generalRateOn(new \DateTimeImmutable($at)));
    }
}

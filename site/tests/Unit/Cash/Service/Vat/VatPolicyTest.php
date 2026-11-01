<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Service\Vat;

use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Service\Vat\VatPolicy;
use App\Cash\Service\Vat\VatRates;
use App\Company\Enum\TaxRegime;
use App\Company\Enum\VatMode;
use App\Company\Facade\CompanyTaxProfileFacadeInterface;
use App\Company\Facade\DTO\CompanyTaxProfileDTO;
use App\Tests\Builders\Company\CompanyBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VatPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{?VatMode, CashDirection, string, ?int}>
     */
    public static function decisionProvider(): iterable
    {
        yield 'режим не выбран' => [null, CashDirection::OUTFLOW, '2026-03-10', null];
        yield 'без НДС' => [VatMode::EXEMPT, CashDirection::OUTFLOW, '2026-03-10', null];
        yield 'УСН 5%, расход' => [VatMode::USN_5, CashDirection::OUTFLOW, '2026-03-10', 5];
        yield 'УСН 5%, поступление' => [VatMode::USN_5, CashDirection::INFLOW, '2026-03-10', null];
        yield 'УСН 7%, расход' => [VatMode::USN_7, CashDirection::OUTFLOW, '2026-03-10', 7];
        yield 'УСН 7%, поступление' => [VatMode::USN_7, CashDirection::INFLOW, '2026-03-10', null];
        yield 'общие ставки, расход 2025' => [VatMode::GENERAL, CashDirection::OUTFLOW, '2025-12-15', 20];
        // Регрессия этапа 1: с 01.01.2026 общая ставка 22%, раньше политика ставила 20% на любую дату.
        yield 'общие ставки, расход 2026' => [VatMode::GENERAL, CashDirection::OUTFLOW, '2026-03-10', 22];
        yield 'общие ставки, поступление 2026' => [VatMode::GENERAL, CashDirection::INFLOW, '2026-03-10', null];
    }

    #[DataProvider('decisionProvider')]
    public function testDecidesRateByVatModeDirectionAndDate(
        ?VatMode $vatMode,
        CashDirection $direction,
        string $occurredOn,
        ?int $expectedRate,
    ): void {
        $profiles = $this->profiles(static fn (): ?VatMode => $vatMode);

        $rate = (new VatPolicy(new VatRates(), $profiles))->decideForCash(CompanyBuilder::DEFAULT_COMPANY_ID, $direction, new \DateTimeImmutable($occurredOn));

        self::assertSame($expectedRate, $rate);
    }

    public function testUsesProfileEffectiveOnOperationDate(): void
    {
        // Без НДС до марта 2026, УСН 5% с марта: ставка зависит от даты операции, а не от текущего режима.
        $profiles = $this->profiles(static fn (\DateTimeImmutable $date): VatMode => $date->format('Y-m') >= '2026-03' ? VatMode::USN_5 : VatMode::EXEMPT);
        $policy = new VatPolicy(new VatRates(), $profiles);

        self::assertNull($policy->decideForCash(CompanyBuilder::DEFAULT_COMPANY_ID, CashDirection::OUTFLOW, new \DateTimeImmutable('2026-02-28')));
        self::assertSame(5, $policy->decideForCash(CompanyBuilder::DEFAULT_COMPANY_ID, CashDirection::OUTFLOW, new \DateTimeImmutable('2026-03-01')));
    }

    public function testInflowDoesNotReadProfile(): void
    {
        $profiles = $this->profiles(static function (): never {
            self::fail('профиль не нужен для поступления');
        });

        self::assertNull((new VatPolicy(new VatRates(), $profiles))->decideForCash(CompanyBuilder::DEFAULT_COMPANY_ID, CashDirection::INFLOW, new \DateTimeImmutable('2026-03-10')));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function generalRateProvider(): iterable
    {
        yield 'последняя секунда 2025' => ['2025-12-31 23:59:59', 20];
        yield 'первая секунда 2026' => ['2026-01-01 00:00:00', 22];
        // Граница — по календарной дате операции, а не по моменту в UTC:
        // 01.01.2026 02:00 по Владивостоку — это ещё 2025 год в UTC.
        yield '01.01.2026 ночью по Владивостоку' => ['2026-01-01 02:00:00 Asia/Vladivostok', 22];
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

    /**
     * @param \Closure(\DateTimeImmutable): ?VatMode $vatModeOn
     */
    private function profiles(\Closure $vatModeOn): CompanyTaxProfileFacadeInterface
    {
        return new class($vatModeOn) implements CompanyTaxProfileFacadeInterface {
            /**
             * @param \Closure(\DateTimeImmutable): ?VatMode $vatModeOn
             */
            public function __construct(private readonly \Closure $vatModeOn)
            {
            }

            public function taxProfileOn(string $companyId, \DateTimeImmutable $date): ?CompanyTaxProfileDTO
            {
                $mode = ($this->vatModeOn)($date);

                return null === $mode ? null : new CompanyTaxProfileDTO(TaxRegime::USN, $mode, null);
            }
        };
    }
}

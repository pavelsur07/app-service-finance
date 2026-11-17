<?php

declare(strict_types=1);

namespace App\Tests\Unit\Finance\Report;

use App\Company\Entity\Company;
use App\Company\Enum\TaxRegime;
use App\Company\Enum\VatMode;
use App\Company\Facade\CompanyTaxProfileFacadeInterface;
use App\Company\Facade\DTO\CompanyTaxProfileDTO;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Facts\InMemoryFactsProvider;
use App\Finance\Report\PlFormulaVariables;
use App\Finance\Report\PlReportCalculator;
use App\Finance\Report\PlReportPeriod;
use App\Finance\Report\PlReportResult;
use App\Finance\Repository\PLCategoryRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Переменные налоговых ставок VAT_RATE / TAX_RATE в формулах статей ОПиУ.
 */
final class PlFormulaVariablesCalculationTest extends TestCase
{
    /**
     * @return iterable<string, array{?CompanyTaxProfileDTO, string, float, float}>
     */
    public static function ratesProvider(): iterable
    {
        yield 'общие ставки, 2026 — 22%' => [new CompanyTaxProfileDTO(TaxRegime::OSNO, VatMode::GENERAL, null), '2026-03-01', 22.0, 0.0];
        yield 'общие ставки, 2025 — 20%' => [new CompanyTaxProfileDTO(TaxRegime::OSNO, VatMode::GENERAL, null), '2025-12-01', 20.0, 0.0];
        yield 'УСН 5% и налог 6%' => [new CompanyTaxProfileDTO(TaxRegime::USN, VatMode::USN_5, null, '6.00'), '2026-03-01', 5.0, 6.0];
        yield 'АУСН 8%, без НДС' => [new CompanyTaxProfileDTO(TaxRegime::AUSN, VatMode::EXEMPT, null, '8.00'), '2026-03-01', 0.0, 8.0];
        yield 'профиль не выбран' => [null, '2026-03-01', 0.0, 0.0];
    }

    #[DataProvider('ratesProvider')]
    public function testVariablesTakeRatesFromTaxProfile(?CompanyTaxProfileDTO $profile, string $month, float $vatRate, float $taxRate): void
    {
        $result = $this->calculate(static fn (): ?CompanyTaxProfileDTO => $profile, $month);

        self::assertSame($vatRate, $this->value($result, 'VAT_PCT'));
        self::assertSame($taxRate, $this->value($result, 'TAX_PCT'));
        self::assertSame(1000.0 * $taxRate / 100, $this->value($result, 'TAX'));
        self::assertNotContains('Unknown code `VAT_RATE` in formula of `НДС, %`', $result->warnings);
    }

    public function testMissingRateIsZeroWithWarning(): void
    {
        // Режим есть, ставка налога не задана: НДС известен, налог — 0 с предупреждением.
        $result = $this->calculate(static fn (): CompanyTaxProfileDTO => new CompanyTaxProfileDTO(TaxRegime::USN, VatMode::EXEMPT, null), '2026-03-01');

        self::assertSame(0.0, $this->value($result, 'TAX'));
        self::assertSame(
            ['Переменная TAX_RATE в формуле «Налог»: ставка не задана в налоговом профиле компании, принято 0', 'Переменная TAX_RATE в формуле «Налог, %»: ставка не задана в налоговом профиле компании, принято 0'],
            $result->warnings,
        );
    }

    public function testRateIsTakenOnLastDayOfPeriod(): void
    {
        // 6% до марта 2026, 15% с марта.
        $profiles = static fn (\DateTimeImmutable $date): CompanyTaxProfileDTO => new CompanyTaxProfileDTO(
            TaxRegime::USN,
            VatMode::EXEMPT,
            null,
            $date->format('Y-m') >= '2026-03' ? '15.00' : '6.00',
        );

        self::assertSame(60.0, $this->value($this->calculate($profiles, '2026-02-01'), 'TAX'));
        self::assertSame(150.0, $this->value($this->calculate($profiles, '2026-03-01'), 'TAX'));
    }

    public function testCategoryWithReservedCodeDoesNotShadowVariable(): void
    {
        $result = $this->calculate(
            static fn (): CompanyTaxProfileDTO => new CompanyTaxProfileDTO(TaxRegime::USN, VatMode::EXEMPT, null, '6.00'),
            '2026-03-01',
            withShadowCategory: true,
        );

        self::assertSame(60.0, $this->value($result, 'TAX'));
    }

    public function testCalculatorWithoutVariablesSourceTreatsThemAsUnset(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $repo = $this->createMock(PLCategoryRepository::class);
        $repo->method('findBy')->willReturn($this->tree($company, false));

        $result = (new PlReportCalculator($repo, new InMemoryFactsProvider(['REV' => 1000.0])))
            ->calculate($company, PlReportPeriod::forMonth(new \DateTimeImmutable('2026-03-01')));

        self::assertSame(0.0, $this->value($result, 'TAX'));
        self::assertCount(3, $result->warnings);
    }

    public function testVariablesSurviveWithFacts(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $repo = $this->createMock(PLCategoryRepository::class);
        $repo->method('findBy')->willReturn($this->tree($company, false));
        $profile = new CompanyTaxProfileDTO(TaxRegime::USN, VatMode::EXEMPT, null, '6.00');

        $result = (new PlReportCalculator($repo, new InMemoryFactsProvider([]), variables: new PlFormulaVariables($this->facade(static fn () => $profile))))
            ->withFacts(new InMemoryFactsProvider(['REV' => 1000.0]))
            ->calculate($company, PlReportPeriod::forMonth(new \DateTimeImmutable('2026-03-01')));

        self::assertSame(60.0, $this->value($result, 'TAX'));
    }

    /**
     * @param \Closure(\DateTimeImmutable): ?CompanyTaxProfileDTO $profileOn
     */
    private function calculate(\Closure $profileOn, string $month, bool $withShadowCategory = false): PlReportResult
    {
        $company = CompanyBuilder::aCompany()->build();
        $repo = $this->createMock(PLCategoryRepository::class);
        $repo->method('findBy')->willReturn($this->tree($company, $withShadowCategory));

        $calculator = new PlReportCalculator(
            $repo,
            new InMemoryFactsProvider(['REV' => 1000.0, 'TAX_RATE' => 999.0]),
            variables: new PlFormulaVariables($this->facade($profileOn)),
        );

        return $calculator->calculate($company, PlReportPeriod::forMonth(new \DateTimeImmutable($month)));
    }

    /**
     * @return list<PLCategory>
     */
    private function tree(Company $company, bool $withShadowCategory): array
    {
        $tree = [
            $this->category($company, 'REV', 'Выручка', PLCategoryType::LEAF_INPUT, null),
            $this->category($company, 'TAX', 'Налог', PLCategoryType::KPI, 'REV * tax_rate / 100'),
            $this->category($company, 'TAX_PCT', 'Налог, %', PLCategoryType::KPI, 'TAX_RATE'),
            $this->category($company, 'VAT_PCT', 'НДС, %', PLCategoryType::KPI, 'VAT_RATE'),
        ];
        if ($withShadowCategory) {
            $tree[] = $this->category($company, 'TAX_RATE', 'Статья с зарезервированным кодом', PLCategoryType::LEAF_INPUT, null);
        }

        return $tree;
    }

    private function category(Company $company, string $code, string $name, PLCategoryType $type, ?string $formula): PLCategory
    {
        $category = new PLCategory(Uuid::uuid4()->toString(), $company);
        $category->setName($name);
        $category->setCode($code);
        $category->setType($type);
        $category->setFormula($formula);

        return $category;
    }

    private function value(PlReportResult $result, string $code): float
    {
        foreach ($result->rows as $row) {
            if ($row->code === $code) {
                return $row->rawValue;
            }
        }
        self::fail("нет строки $code");
    }

    /**
     * @param \Closure(\DateTimeImmutable): ?CompanyTaxProfileDTO $profileOn
     */
    private function facade(\Closure $profileOn): CompanyTaxProfileFacadeInterface
    {
        return new class($profileOn) implements CompanyTaxProfileFacadeInterface {
            /**
             * @param \Closure(\DateTimeImmutable): ?CompanyTaxProfileDTO $profileOn
             */
            public function __construct(private readonly \Closure $profileOn)
            {
            }

            public function taxProfileOn(string $companyId, \DateTimeImmutable $date): ?CompanyTaxProfileDTO
            {
                return ($this->profileOn)($date);
            }
        };
    }
}

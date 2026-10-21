<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Company\Enum\BusinessProfile;
use App\Finance\Application\Action\EnsureDefaultPLTreeAction;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLFlow;
use App\Finance\Facts\FactsProviderInterface;
use App\Finance\Report\PlReportCalculator;
use App\Finance\Report\PlReportPeriod;
use App\Finance\Repository\PLCategoryRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Итоги шаблонных деревьев ОПиУ (универсального, а для статей ниже EBITDA — обоих) считаются тем же калькулятором отчёта, что и для любой
 * компании: формулы шаблона ссылаются на существующие коды, а знаки — от фактов (расход
 * отрицательный), поток статьи в расчёт не входит.
 */
final class UniversalPLTemplateCalculationTest extends IntegrationTestCase
{
    public function testTotalsOfUniversalTreeFollowFromFacts(): void
    {
        $company = $this->companyWithUniversalTree();

        $values = $this->calculate($company, [
            'REV_SALES' => 1000.0,
            'REV_SALES_RETURNS' => -100.0,
            'COGS_PRODUCT_REV' => -400.0,
            'COGS_DELIVERY' => -50.0,
            'OVERHEAD_ADMIN_PAYROLL' => -120.0,
            'OVERHEAD_SALES_MARKETING' => -80.0,
            'BELOW_EBITDA_USN' => -30.0,
            'BELOW_EBITDA_OTHER_INCOME' => 10.0,
        ]);

        self::assertSame(
            ['REV_TOTAL' => 900.0, 'REV_SALES_TOTAL' => 900.0, 'COGS_TOTAL' => -450.0, 'GROSS_PROFIT' => 450.0, 'GROSS_MARGIN' => 0.5, 'OVERHEAD_TOTAL' => -200.0, 'EBITDA' => 250.0, 'EBITDAK' => 0.2778, 'BELOW_EBITDA_TOTAL' => -30.0, 'BELOW_EBITDA_INCOME_TOTAL' => 10.0, 'NET_PROFIT' => 230.0],
            array_intersect_key($values, array_flip(['REV_TOTAL', 'REV_SALES_TOTAL', 'COGS_TOTAL', 'GROSS_PROFIT', 'GROSS_MARGIN', 'OVERHEAD_TOTAL', 'EBITDA', 'EBITDAK', 'BELOW_EBITDA_TOTAL', 'BELOW_EBITDA_INCOME_TOTAL', 'NET_PROFIT'])),
        );
    }

    /**
     * Каждая строка ввода доходит до чистой прибыли ровно один раз, кроме дивидендов —
     * распределение прибыли, а не расход.
     */
    public function testEveryInputLineReachesNetProfitOnceExceptDividends(): void
    {
        $company = $this->companyWithUniversalTree();
        $leaves = $this->connection->fetchFirstColumn(
            "SELECT code FROM pl_categories WHERE company_id = ? AND type = 'LEAF_INPUT'",
            [(string) $company->getId()],
        );
        self::assertContains('PROFIT_DISTR_DIVIDENDS', $leaves);

        $values = $this->calculate($company, array_fill_keys($leaves, -1.0));

        self::assertSame(-(float) (\count($leaves) - 1), $values['NET_PROFIT']);
    }

    /**
     * Регрессия: «Выручка» была KPI с формулой по двум кодам — строка, которую пользователь
     * добавит под неё («Выручка от услуг»), выпадала из всех итогов.
     */
    public function testRevenueLineAddedByUserCountsInTotals(): void
    {
        $company = $this->companyWithUniversalTree();
        $revenue = $this->em->getRepository(PLCategory::class)->findOneBy(['company' => $company, 'code' => 'REV_SALES_TOTAL']);
        self::assertInstanceOf(PLCategory::class, $revenue);
        $this->em->persist(PLCategoryBuilder::aPLCategory()->forCompany($company)->withParent($revenue)->withName('Выручка от услуг')->withCode('REV_SERVICES')->withType(PLCategoryType::LEAF_INPUT)->withFlow(PLFlow::INCOME)->build());
        $this->em->flush();
        $this->em->clear();
        $company = $this->em->find(Company::class, $company->getId());
        self::assertInstanceOf(Company::class, $company);

        $values = $this->calculate($company, ['REV_SALES' => 1000.0, 'REV_SERVICES' => 500.0]);

        self::assertSame([1500.0, 1500.0, 1500.0], [$values['REV_TOTAL'], $values['GROSS_PROFIT'], $values['NET_PROFIT']]);
    }

    /**
     * Амортизация и налог на прибыль — ниже EBITDA: уменьшают чистую прибыль, EBITDA не меняют.
     */
    #[DataProvider('profiles')]
    public function testDepreciationAndProfitTaxReduceNetProfitOnly(BusinessProfile $profile, int $categories): void
    {
        $company = $this->companyWithTree($profile, $categories);
        $base = $this->calculate($company, ['REV_SALES' => 1000.0, 'REV_NOT_SPP' => 1000.0]);

        $values = $this->calculate($company, ['REV_SALES' => 1000.0, 'REV_NOT_SPP' => 1000.0, 'BELOW_EBITDA_DEPRECIATION' => -10.0, 'BELOW_EBITDA_PROFIT_TAX' => -20.0]);

        self::assertGreaterThan(0.0, $base['EBITDA']);
        self::assertSame([$base['EBITDA'], -30.0, $base['NET_PROFIT'] - 30.0], [$values['EBITDA'], $values['BELOW_EBITDA_TOTAL'], $values['NET_PROFIT']]);
    }

    /**
     * @return iterable<string, array{BusinessProfile, int}>
     */
    public static function profiles(): iterable
    {
        yield 'marketplace' => [BusinessProfile::MARKETPLACE, 72];
        yield 'universal' => [BusinessProfile::UNIVERSAL, 44];
    }

    private function companyWithUniversalTree(): Company
    {
        return $this->companyWithTree(BusinessProfile::UNIVERSAL, 44);
    }

    private function companyWithTree(BusinessProfile $profile, int $categories): Company
    {
        $owner = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();
        self::assertSame($categories, (self::getContainer()->get(EnsureDefaultPLTreeAction::class))((string) $company->getId(), profile: $profile));
        $this->em->clear();
        $company = $this->em->find(Company::class, $company->getId());
        self::assertInstanceOf(Company::class, $company);

        return $company;
    }

    /**
     * @param array<string, float> $facts код строки ввода → факт
     *
     * @return array<string, float> код → значение строки отчёта
     */
    private function calculate(Company $company, array $facts): array
    {
        $calculator = new PlReportCalculator(self::getContainer()->get(PLCategoryRepository::class), new class($facts) implements FactsProviderInterface {
            /** @param array<string, float> $facts */
            public function __construct(private array $facts)
            {
            }

            public function value(Company $company, PlReportPeriod $period, string $code, ProjectDirection|array|null $projectDirection = null, string|array|null $responsibilityCenterId = null): float
            {
                return $this->facts[$code] ?? 0.0;
            }
        });

        $result = $calculator->calculate($company, new PlReportPeriod(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'), 'Октябрь'));
        self::assertSame([], $result->warnings, 'Формулы шаблона ссылаются на существующие коды');

        $values = [];
        foreach ($result->rows as $row) {
            $values[(string) $row->code] = round($row->rawValue, 4);
        }

        return $values;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Company\Enum\BusinessProfile;
use App\Finance\Application\Action\EnsureDefaultPLTreeAction;
use App\Finance\Facts\FactsProviderInterface;
use App\Finance\Report\PlReportCalculator;
use App\Finance\Report\PlReportPeriod;
use App\Finance\Repository\PLCategoryRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

/**
 * Итоги универсального дерева ОПиУ считаются тем же калькулятором отчёта, что и для любой
 * компании: формулы шаблона ссылаются на существующие коды, а знаки — от фактов (расход
 * отрицательный), поток статьи в расчёт не входит.
 */
final class UniversalPLTemplateCalculationTest extends IntegrationTestCase
{
    public function testTotalsOfUniversalTreeFollowFromFacts(): void
    {
        $owner = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();
        self::assertSame(41, (self::getContainer()->get(EnsureDefaultPLTreeAction::class))((string) $company->getId(), profile: BusinessProfile::UNIVERSAL));
        $this->em->clear();
        $company = $this->em->find(Company::class, $company->getId());
        self::assertInstanceOf(Company::class, $company);

        $facts = [
            'REV_SALES' => 1000.0,
            'REV_SALES_RETURNS' => -100.0,
            'COGS_PRODUCT_REV' => -400.0,
            'COGS_DELIVERY' => -50.0,
            'OVERHEAD_ADMIN_PAYROLL' => -120.0,
            'OVERHEAD_SALES_MARKETING' => -80.0,
            'BELOW_EBITDA_USN' => -30.0,
            'BELOW_EBITDA_OTHER_INCOME' => 10.0,
        ];
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
        $values = [];
        foreach ($result->rows as $row) {
            $values[(string) $row->code] = round($row->rawValue, 4);
        }

        self::assertSame([], $result->warnings, 'Формулы шаблона ссылаются на существующие коды');
        self::assertSame(
            ['REV_TOTAL' => 900.0, 'COGS_TOTAL' => -450.0, 'GROSS_PROFIT' => 450.0, 'GROSS_MARGIN' => 0.5, 'OVERHEAD_TOTAL' => -200.0, 'EBITDA' => 250.0, 'EBITDAK' => 0.2778, 'BELOW_EBITDA_TOTAL' => -30.0, 'BELOW_EBITDA_INCOME_TOTAL' => 10.0, 'NET_PROFIT' => 230.0],
            array_intersect_key($values, array_flip(['REV_TOTAL', 'COGS_TOTAL', 'GROSS_PROFIT', 'GROSS_MARGIN', 'OVERHEAD_TOTAL', 'EBITDA', 'EBITDAK', 'BELOW_EBITDA_TOTAL', 'BELOW_EBITDA_INCOME_TOTAL', 'NET_PROFIT'])),
        );
    }
}

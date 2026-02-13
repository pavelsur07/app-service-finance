<?php

namespace App\Tests\Unit\Analytics;

use App\Analytics\Application\Widget\ProfitWidgetBuilder;
use App\Analytics\Domain\Period;
use App\Company\Entity\Company;
use App\Finance\Facts\FactsProviderInterface;
use App\Finance\Report\PlReportCalculator;
use App\Finance\Report\PlReportGridBuilder;
use App\Repository\PLCategoryRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ProfitWidgetBuilderTest extends TestCase
{
    public function testBuildContainsProfitKeysAndDelta(): void
    {
        $company = $this->createCompany('76f4b0c3-6fd3-41bb-b426-0ea2fd21ae12');
        $period = new Period(new DateTimeImmutable('2026-03-01'), new DateTimeImmutable('2026-03-31'));

        $categoryRepository = $this->createMock(PLCategoryRepository::class);
        $categoryRepository->method('findBy')->willReturn([]);

        $factsProvider = $this->createMock(FactsProviderInterface::class);
        $factsProvider->method('value')->willReturn(0.0);

        $plReportGridBuilder = new PlReportGridBuilder(new PlReportCalculator($categoryRepository, $factsProvider));

        $builder = new ProfitWidgetBuilder($plReportGridBuilder, $categoryRepository);

        $result = $builder->build($company, $period);

        self::assertArrayHasKey('revenue', $result);
        self::assertArrayHasKey('variable_costs', $result);
        self::assertArrayHasKey('opex', $result);
        self::assertArrayHasKey('ebitda', $result);
        self::assertArrayHasKey('margin_pct', $result);
        self::assertArrayHasKey('delta', $result);

        self::assertSame(0.0, $result['revenue']);
        self::assertSame(0.0, $result['variable_costs']);
        self::assertSame(0.0, $result['opex']);
        self::assertSame(0.0, $result['ebitda']);
        self::assertSame(0.0, $result['margin_pct']);
        self::assertSame(0.0, $result['delta']['ebitda_abs']);
        self::assertSame(0.0, $result['delta']['margin_pp']);
    }

    private function createCompany(string $companyId): Company
    {
        $company = $this->getMockBuilder(Company::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();

        $company->method('getId')->willReturn($companyId);

        return $company;
    }

}

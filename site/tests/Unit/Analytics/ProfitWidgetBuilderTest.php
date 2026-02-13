<?php

namespace App\Tests\Unit\Analytics;

use App\Analytics\Application\Widget\ProfitWidgetBuilder;
use App\Analytics\Domain\Period;
use App\Company\Entity\Company;
use App\Entity\PLCategory;
use App\Enum\PLExpenseType;
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

        $revenueCategory = $this->createCategory('cat-revenue', true, false, PLExpenseType::OTHER, null);
        $variableCategory = $this->createCategory('cat-variable', false, true, PLExpenseType::VARIABLE, $this->createMock(PLCategory::class));
        $opexCategory = $this->createCategory('cat-opex', false, true, PLExpenseType::OPEX, $this->createMock(PLCategory::class));

        $categoryRepository = $this->createMock(PLCategoryRepository::class);
        $categoryRepository->method('findBy')->willReturn([$revenueCategory, $variableCategory, $opexCategory]);

        $plReportGridBuilder = $this->createMock(PlReportGridBuilder::class);
        $plReportGridBuilder->expects(self::exactly(2))
            ->method('build')
            ->willReturnOnConsecutiveCalls(
                [
                    'rawValues' => [
                        'cat-revenue' => ['2026-03' => 1000.0],
                        'cat-variable' => ['2026-03' => 300.0],
                        'cat-opex' => ['2026-03' => 200.0],
                    ],
                ],
                [
                    'rawValues' => [
                        'cat-revenue' => ['2026-02' => 800.0],
                        'cat-variable' => ['2026-02' => 250.0],
                        'cat-opex' => ['2026-02' => 250.0],
                    ],
                ],
            );

        $builder = new ProfitWidgetBuilder($plReportGridBuilder, $categoryRepository);

        $result = $builder->build($company, $period);

        self::assertArrayHasKey('revenue', $result);
        self::assertArrayHasKey('variable_costs', $result);
        self::assertArrayHasKey('opex', $result);
        self::assertArrayHasKey('ebitda', $result);
        self::assertArrayHasKey('margin_pct', $result);
        self::assertArrayHasKey('delta', $result);

        self::assertSame(1000.0, $result['revenue']);
        self::assertSame(300.0, $result['variable_costs']);
        self::assertSame(200.0, $result['opex']);
        self::assertSame(500.0, $result['ebitda']);
        self::assertSame(50.0, $result['margin_pct']);
        self::assertSame(200.0, $result['delta']['ebitda_abs']);
        self::assertSame(12.5, $result['delta']['margin_pp']);
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

    private function createCategory(string $id, bool $isIncomeRoot, bool $isExpenseRoot, PLExpenseType $expenseType, ?PLCategory $parent): PLCategory
    {
        $category = $this->createMock(PLCategory::class);
        $category->method('getId')->willReturn($id);
        $category->method('isIncomeRoot')->willReturn($isIncomeRoot);
        $category->method('isExpenseRoot')->willReturn($isExpenseRoot);
        $category->method('getExpenseType')->willReturn($expenseType);
        $category->method('getParent')->willReturn($parent);

        return $category;
    }
}

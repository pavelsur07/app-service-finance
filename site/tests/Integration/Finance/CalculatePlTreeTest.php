<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Finance\Application\DTO\PlPreviewColumn;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLFlow;
use App\Finance\Enum\PlPreviewUnplacedReason;
use App\Finance\Facade\FinanceFacade;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

/**
 * FinanceFacade::calculatePlTree(): дерево ОПиУ из сумм, которые уже значения отчёта (доход − расход),
 * — без перевода по flow статьи, в отличие от calculatePlPreview().
 */
final class CalculatePlTreeTest extends IntegrationTestCase
{
    private const COMPANY_ID = '0193dddd-0000-7000-8000-00000000c001';
    private const PROFIT_ID = '0193dddd-0000-7000-8000-00000000d001';
    private const REVENUE_ID = '0193dddd-0000-7000-8000-00000000d002';
    private const RENT_ID = '0193dddd-0000-7000-8000-00000000d003';
    private const NO_CODE_ID = '0193dddd-0000-7000-8000-00000000d004';
    private const MARGIN_ID = '0193dddd-0000-7000-8000-00000000d005';

    public function testTreeKeepsReportValueSignsAndComputesSubtotalAndKpi(): void
    {
        $owner = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->withOwner($owner)->build();
        $profit = PLCategoryBuilder::aPLCategory()->withId(self::PROFIT_ID)->forCompany($company)->withName('Прибыль')->withCode('PROFIT')->withType(PLCategoryType::SUBTOTAL)->build();
        $revenue = PLCategoryBuilder::aPLCategory()->withId(self::REVENUE_ID)->forCompany($company)->withName('Выручка')->withCode('REV')->withFlow(PLFlow::INCOME)->withParent($profit)->build();
        $rent = PLCategoryBuilder::aPLCategory()->withId(self::RENT_ID)->forCompany($company)->withName('Аренда')->withCode('RENT')->withFlow(PLFlow::EXPENSE)->withParent($profit)->build();
        $rent->setSortOrder(1);
        $noCode = PLCategoryBuilder::aPLCategory()->withId(self::NO_CODE_ID)->forCompany($company)->withName('Без кода')->withParent($profit)->build();
        $noCode->setSortOrder(2);
        $margin = PLCategoryBuilder::aPLCategory()->withId(self::MARGIN_ID)->forCompany($company)->withName('Маржа')->withCode('MARGIN')->withType(PLCategoryType::KPI)->build();
        $margin->setFormula('PROFIT / REV * 100');
        $margin->setSortOrder(1);
        foreach ([$owner, $company, $profit, $revenue, $rent, $noCode, $margin] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->em->clear();

        $result = self::getContainer()->get(FinanceFacade::class)->calculatePlTree(
            self::COMPANY_ID,
            new \DateTimeImmutable('2027-01-01'),
            new \DateTimeImmutable('2027-12-31'),
            [
                new PlPreviewColumn('2027-01', [self::REVENUE_ID => '1000', self::RENT_ID => '-300', self::NO_CODE_ID => '50']),
                new PlPreviewColumn('2027-02', [self::REVENUE_ID => '-100']),
            ],
        );

        $values = [];
        foreach ($result->rows as $row) {
            $values[$row->id] = $row->values;
        }
        self::assertSame(['2027-01' => 1000.0, '2027-02' => -100.0], $values[self::REVENUE_ID]);
        self::assertSame(['2027-01' => -300.0, '2027-02' => 0.0], $values[self::RENT_ID]);
        self::assertSame(['2027-01' => 700.0, '2027-02' => -100.0], $values[self::PROFIT_ID]);
        self::assertEqualsWithDelta(70.0, $values[self::MARGIN_ID]['2027-01'], 0.0001);

        self::assertCount(1, $result->unplaced);
        self::assertSame(self::NO_CODE_ID, $result->unplaced[0]->categoryId);
        self::assertSame(PlPreviewUnplacedReason::NO_CODE, $result->unplaced[0]->reason);
    }
}

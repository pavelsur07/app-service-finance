<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Company\Entity\Company;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\ProjectDirection;
use App\Finance\Entity\PLCategory;
use App\Finance\Entity\PLDailyTotal;
use App\Finance\Enum\PLFlow;
use App\Finance\Facade\FinanceFacade;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

/**
 * Факт ОПиУ по статьям и месяцам года для план–факт бюджета: знак отчёта (доход − расход),
 * точный проект (у дочернего проекта своя пара и свой план), ЦФО, только своя компания.
 */
final class PlFactAmountsByMonthTest extends IntegrationTestCase
{
    private const COMPANY_ID = '0193dddd-0000-7000-8000-00000000c001';
    private const OTHER_COMPANY_ID = '0193dddd-0000-7000-8000-00000000c002';
    private const REV_ID = '0193dddd-0000-7000-8000-00000000d001';
    private const RENT_ID = '0193dddd-0000-7000-8000-00000000d002';
    private const OTHER_REV_ID = '0193dddd-0000-7000-8000-00000000d009';
    private const GENERAL_ID = '0193dddd-0000-7000-8000-00000000e001';
    private const SHOP_ID = '0193dddd-0000-7000-8000-00000000e002';
    private const SIDE_ID = '0193dddd-0000-7000-8000-00000000e003';
    private const OTHER_PROJECT_ID = '0193dddd-0000-7000-8000-00000000e009';

    private int $n = 0;
    private string $centerId;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = UserBuilder::aUser()->withId('0193dddd-0000-7000-8000-00000000b001')->withEmail('fact-owner@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->withOwner($owner)->build();
        $rev = PLCategoryBuilder::aPLCategory()->withId(self::REV_ID)->forCompany($company)->withName('Выручка')->withCode('REV')->withFlow(PLFlow::INCOME)->build();
        $rent = PLCategoryBuilder::aPLCategory()->withId(self::RENT_ID)->forCompany($company)->withName('Аренда')->withCode('RENT')->withFlow(PLFlow::EXPENSE)->build();
        $general = new ProjectDirection(self::GENERAL_ID, $company, 'Общий', ProjectDirection::CODE_GENERAL);
        $shop = new ProjectDirection(self::SHOP_ID, $company, 'Магазин');
        $shop->setParent($general);
        $side = new ProjectDirection(self::SIDE_ID, $company, 'Сторонний');
        $center = new FinancialResponsibilityCenter(self::COMPANY_ID, 'SALES', 'Продажи');
        $this->centerId = $center->getId();

        $otherOwner = UserBuilder::aUser()->withId('0193dddd-0000-7000-8000-00000000b002')->withEmail('fact-other@example.test')->build();
        $otherCompany = CompanyBuilder::aCompany()->withId(self::OTHER_COMPANY_ID)->withOwner($otherOwner)->build();
        $otherRev = PLCategoryBuilder::aPLCategory()->withId(self::OTHER_REV_ID)->forCompany($otherCompany)->withCode('REV')->build();
        $otherProject = new ProjectDirection(self::OTHER_PROJECT_ID, $otherCompany, 'Общий', ProjectDirection::CODE_GENERAL);

        foreach ([$owner, $company, $rev, $rent, $general, $shop, $side, $center, $otherOwner, $otherCompany, $otherRev, $otherProject] as $entity) {
            $this->em->persist($entity);
        }

        $this->total($company, $general, $rev, '2027-01-10', income: '1000.00');
        $this->total($company, $shop, $rev, '2027-01-20', income: '500.50');
        $this->total($company, $general, $rent, '2027-02-05', expense: '300.00', center: $this->centerId);
        $this->total($company, $general, $rev, '2027-02-06', income: '40.00', expense: '10.00');
        $this->total($company, $side, $rev, '2027-03-01', income: '70.00');
        $this->total($company, $general, $rev, '2026-12-31', income: '999.00');
        $this->total($company, $general, $rev, '2028-01-01', income: '999.00');
        $this->total($company, $general, null, '2027-01-15', income: '555.00');
        $this->total($otherCompany, $otherProject, $otherRev, '2027-01-10', income: '777.00');
        $this->em->flush();
        $this->em->clear();
    }

    public function testWholeCompanyByMonthInReportSign(): void
    {
        self::assertSame([
            '2027-01' => [self::REV_ID => '1500.50'],
            '2027-02' => [self::REV_ID => '30.00', self::RENT_ID => '-300.00'],
            '2027-03' => [self::REV_ID => '70.00'],
        ], $this->facade()->getPlFactAmountsByMonth(self::COMPANY_ID, 2027));
    }

    public function testProjectIsExactWithoutChildProjects(): void
    {
        self::assertSame([
            '2027-01' => [self::REV_ID => '1000.00'],
            '2027-02' => [self::REV_ID => '30.00', self::RENT_ID => '-300.00'],
        ], $this->facade()->getPlFactAmountsByMonth(self::COMPANY_ID, 2027, self::GENERAL_ID));

        self::assertSame(['2027-01' => [self::REV_ID => '500.50']], $this->facade()->getPlFactAmountsByMonth(self::COMPANY_ID, 2027, self::SHOP_ID));
    }

    public function testResponsibilityCenterFilters(): void
    {
        self::assertSame(
            ['2027-02' => [self::RENT_ID => '-300.00']],
            $this->facade()->getPlFactAmountsByMonth(self::COMPANY_ID, 2027, self::GENERAL_ID, $this->centerId),
        );
    }

    public function testUnassignedCenterJoinsWhenAsked(): void
    {
        // Факт без ЦФО (1000 и 40−10 в «Общем») добавляется к ЦФО, только если вызывающий просит.
        self::assertSame(
            ['2027-01' => [self::REV_ID => '1000.00'], '2027-02' => [self::REV_ID => '30.00', self::RENT_ID => '-300.00']],
            $this->facade()->getPlFactAmountsByMonth(self::COMPANY_ID, 2027, self::GENERAL_ID, $this->centerId, includeUnassignedCenter: true),
        );
    }

    public function testForeignProjectGivesNothing(): void
    {
        self::assertSame([], $this->facade()->getPlFactAmountsByMonth(self::COMPANY_ID, 2027, self::OTHER_PROJECT_ID));
    }

    private function facade(): FinanceFacade
    {
        return self::getContainer()->get(FinanceFacade::class);
    }

    private function total(Company $company, ProjectDirection $project, ?PLCategory $category, string $date, string $income = '0', string $expense = '0', ?string $center = null): void
    {
        $total = new PLDailyTotal(sprintf('0193dddd-0000-7000-8000-0000000a%04d', ++$this->n), $company, $project, new \DateTimeImmutable($date), $category);
        $total->setAmountIncome($income);
        $total->setAmountExpense($expense);
        $total->setResponsibilityCenterId($center);
        $this->em->persist($total);
    }
}

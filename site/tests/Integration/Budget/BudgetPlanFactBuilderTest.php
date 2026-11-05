<?php

declare(strict_types=1);

namespace App\Tests\Integration\Budget;

use App\Budget\Application\Service\BudgetPlanFactBuilder;
use App\Budget\Application\Service\BudgetPlanFactFilterResolver;
use App\Budget\Application\View\BudgetPlanFactColumn;
use App\Budget\Application\View\BudgetPlanFactFilter;
use App\Budget\Application\View\BudgetPlanFactRow;
use App\Budget\Application\View\BudgetPlanFactView;
use App\Budget\Entity\BudgetVersion;
use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Finance\Entity\PLCategory;
use App\Finance\Entity\PLDailyTotal;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLValueFormat;
use App\Tests\Builders\Budget\BudgetLineBuilder;
use App\Tests\Builders\Budget\BudgetVersionBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class BudgetPlanFactBuilderTest extends BudgetIntegrationTestCase
{
    use ClockSensitiveTrait;

    private const MARGIN_ID = '0193cccc-0000-7000-8000-00000000d007';
    private const VERSION_ID = '0193cccc-0000-7000-8000-0000000f6001';

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime(new \DateTimeImmutable('2028-01-10 12:00', new \DateTimeZone('Europe/Moscow')));
        $version = BudgetVersionBuilder::aVersion()->withId(self::VERSION_ID)->forCompany(self::COMPANY_ID)->withYear(2027)->build();
        $this->em->persist($version);
        $this->line($version, self::REVENUE_ID, '2027-01', '1000.00', self::GENERAL_PROJECT_ID, $this->generalCenterId);
        $this->line($version, self::RENT_ID, '2027-02', '-300.00', self::GENERAL_PROJECT_ID, $this->generalCenterId);
        $this->line($version, self::REVENUE_ID, '2027-01', '200.00', self::SHOP_PROJECT_ID, $this->salesCenterId);

        $this->fact(self::REVENUE_ID, self::GENERAL_PROJECT_ID, $this->generalCenterId, '2027-01-15', income: '1150.00');
        $this->fact(self::RENT_ID, self::GENERAL_PROJECT_ID, $this->generalCenterId, '2027-02-03', expense: '310.00');
        $this->fact(self::REVENUE_ID, self::GENERAL_PROJECT_ID, $this->generalCenterId, '2027-03-09', income: '50.00');
        $this->fact(self::REVENUE_ID, self::SHOP_PROJECT_ID, $this->salesCenterId, '2027-01-20', income: '100.00');
        $this->em->flush();
        $this->em->clear();
    }

    public function testPairComparesPlanWithFactOfSamePair(): void
    {
        $view = $this->build(self::GENERAL_PROJECT_ID, $this->generalCenterId);

        self::assertSame(10, $view->thresholdPercent);
        self::assertSame([self::SUBTOTAL_ID, self::REVENUE_ID, self::RENT_ID], array_map(static fn (BudgetPlanFactRow $row): string => $row->id, $view->rows));

        $jan = $this->row($view, self::REVENUE_ID)->cells['2027-01'];
        self::assertSame(['1000.00', '1150.00', '150.00', '15.0', true], [$jan->plan, $jan->fact, $jan->diff, $jan->percent, $jan->significant]);

        $feb = $this->row($view, self::RENT_ID)->cells['2027-02'];
        self::assertSame(['-300.00', '-310.00', '-10.00', '-3.3', false], [$feb->plan, $feb->fact, $feb->diff, $feb->percent, $feb->significant]);

        $mar = $this->row($view, self::REVENUE_ID)->cells['2027-03'];
        self::assertSame(['0.00', '50.00', null, true], [$mar->plan, $mar->fact, $mar->percent, $mar->significant]);

        $ytd = $this->row($view, self::REVENUE_ID)->cells[BudgetPlanFactColumn::YTD];
        self::assertSame(['1000.00', '1200.00'], [$ytd->plan, $ytd->fact]);

        $profitJan = $this->row($view, self::SUBTOTAL_ID)->cells['2027-01'];
        self::assertSame(['1000.00', '1150.00'], [$profitJan->plan, $profitJan->fact]);
        self::assertCount(18, $this->row($view, self::SUBTOTAL_ID)->cells);
    }

    public function testGeneralCenterPairIncludesFactWithoutCenter(): void
    {
        $this->fact(self::REVENUE_ID, self::GENERAL_PROJECT_ID, null, '2027-01-25', income: '7.00');
        $this->fact(self::REVENUE_ID, self::SHOP_PROJECT_ID, null, '2027-01-26', income: '9.00');
        $this->em->flush();
        $this->em->clear();

        // Системный ЦФО «Общий»: факт без ЦФО того же проекта — его (решение Владельца 04.11, вариант A).
        self::assertSame('1157.00', $this->row($this->build(self::GENERAL_PROJECT_ID, $this->generalCenterId), self::REVENUE_ID)->cells['2027-01']->fact);
        // Обычный ЦФО — только свои строки.
        self::assertSame('100.00', $this->row($this->build(self::SHOP_PROJECT_ID, $this->salesCenterId), self::REVENUE_ID)->cells['2027-01']->fact);
    }

    public function testWithoutPairWholeCompany(): void
    {
        $jan = $this->row($this->build(null, null), self::REVENUE_ID)->cells['2027-01'];

        self::assertSame(['1200.00', '1250.00'], [$jan->plan, $jan->fact]);
    }

    public function testFilterProjectIncludesChildrenInPlanAndFact(): void
    {
        $childId = $this->childProject(self::SHOP_PROJECT_ID, 'Витрина');
        $version = $this->em->find(BudgetVersion::class, self::VERSION_ID);
        self::assertNotNull($version);
        $this->line($version, self::REVENUE_ID, '2027-01', '50.00', $childId, $this->salesCenterId);
        $this->fact(self::REVENUE_ID, $childId, $this->salesCenterId, '2027-01-21', income: '30.00');
        $this->em->flush();
        $this->em->clear();

        $filter = $this->filter([self::SHOP_PROJECT_ID], []);
        self::assertSame([self::SHOP_PROJECT_ID], $filter->selectedProjectIds);
        self::assertSame([self::SHOP_PROJECT_ID, $childId], $filter->projectIds);
        self::assertTrue($filter->reportLinkable());
        self::assertSame(['projectFiltersPresent' => 1, 'projectDirectionIds' => [self::SHOP_PROJECT_ID]], $filter->reportQuery());

        $jan = $this->row($this->buildFiltered($filter), self::REVENUE_ID)->cells['2027-01'];
        self::assertSame(['250.00', '130.00'], [$jan->plan, $jan->fact]);

        // Только дочерний — без родителя.
        $child = $this->row($this->buildFiltered($this->filter([$childId], [])), self::REVENUE_ID)->cells['2027-01'];
        self::assertSame(['50.00', '30.00'], [$child->plan, $child->fact]);
    }

    public function testFilterCentersIncludeFactWithoutCenterOnlyWithGeneral(): void
    {
        $this->fact(self::REVENUE_ID, self::GENERAL_PROJECT_ID, null, '2027-01-25', income: '7.00');
        $this->fact(self::REVENUE_ID, self::SHOP_PROJECT_ID, null, '2027-01-26', income: '9.00');
        $this->em->flush();
        $this->em->clear();

        $sales = $this->filter([], [$this->salesCenterId]);
        self::assertFalse($sales->includeUnassigned);
        self::assertTrue($sales->reportLinkable());
        $jan = $this->row($this->buildFiltered($sales), self::REVENUE_ID)->cells['2027-01'];
        self::assertSame(['200.00', '100.00'], [$jan->plan, $jan->fact]);

        // «Общий» — и факт без ЦФО всех проектов; отчёт ОПиУ с фильтром ЦФО его не покажет — без ссылок.
        $general = $this->filter([], [$this->generalCenterId]);
        self::assertTrue($general->includeUnassigned);
        self::assertFalse($general->reportLinkable());
        $jan = $this->row($this->buildFiltered($general), self::REVENUE_ID)->cells['2027-01'];
        self::assertSame(['1000.00', '1166.00'], [$jan->plan, $jan->fact]);

        // Проект «Магазин» × «Общий»: только факт без ЦФО этого проекта.
        $jan = $this->row($this->buildFiltered($this->filter([self::SHOP_PROJECT_ID], [$this->generalCenterId])), self::REVENUE_ID)->cells['2027-01'];
        self::assertSame(['0.00', '9.00'], [$jan->plan, $jan->fact]);
    }

    public function testFilterAllSelectedOrUnknownIsWholeCompany(): void
    {
        foreach ([
            [[], []],
            [[self::GENERAL_PROJECT_ID, self::SHOP_PROJECT_ID], [$this->generalCenterId, $this->salesCenterId]],
            [[self::OTHER_COMPANY_PROJECT_ID, 'not-a-uuid'], [$this->otherCompanyCenterId, $this->archivedCenterId]],
        ] as [$projects, $centers]) {
            $filter = $this->filter($projects, $centers);
            self::assertTrue($filter->isAll());
            self::assertSame([], $filter->query());
            self::assertSame('Все', $filter->projectLabel());
            $jan = $this->row($this->buildFiltered($filter), self::REVENUE_ID)->cells['2027-01'];
            self::assertSame(['1200.00', '1250.00'], [$jan->plan, $jan->fact]);
        }

        // Чужой id рядом со своим отбрасывается.
        $filter = $this->filter([self::SHOP_PROJECT_ID, self::OTHER_COMPANY_PROJECT_ID], [$this->salesCenterId, $this->otherCompanyCenterId]);
        self::assertSame(['projects' => [self::SHOP_PROJECT_ID], 'centers' => [$this->salesCenterId]], $filter->query());
        self::assertSame(['Магазин', 'Продажи'], [$filter->projectLabel(), $filter->centerLabel()]);
    }

    public function testColumnsFollowClosedMonthsAndQuartersAreCalculatedNotSummed(): void
    {
        $company = $this->em->find(Company::class, self::COMPANY_ID);
        self::assertNotNull($company);
        $margin = PLCategoryBuilder::aPLCategory()->withId(self::MARGIN_ID)->forCompany($company)->withName('Маржа')->withCode('MARGIN')->withType(PLCategoryType::KPI)->build();
        $margin->setFormula('PROFIT / REV');
        $margin->setFormat(PLValueFormat::RATIO);
        $margin->setSortOrder(5);
        $this->em->persist($margin);
        $this->em->flush();
        $this->em->clear();
        self::mockTime(new \DateTimeImmutable('2027-03-15 12:00', new \DateTimeZone('Europe/Moscow')));

        $view = $this->build(self::GENERAL_PROJECT_ID, $this->generalCenterId);

        $columns = [];
        foreach ($view->columns as $column) {
            $columns[$column->key] = [$column->kind, $column->state, $column->months];
        }
        self::assertSame(['month', 'closed', ['2027-02']], $columns['2027-02']);
        self::assertSame(['month', 'current', ['2027-03']], $columns['2027-03']);
        self::assertSame(['month', 'future', ['2027-04']], $columns['2027-04']);
        self::assertSame(['quarter', 'partial', ['2027-01', '2027-02']], $columns['2027-Q1']);
        self::assertSame(['quarter', 'future', ['2027-04', '2027-05', '2027-06']], $columns['2027-Q2']);
        self::assertSame(['ytd', 'closed', ['2027-01', '2027-02']], $columns[BudgetPlanFactColumn::YTD]);
        self::assertSame('year', $columns['total'][0]);
        self::assertSame('2027-03', $view->currentMonth);

        $mar = $this->row($view, self::REVENUE_ID)->cells['2027-03'];
        self::assertSame(['0.00', '50.00', null, null, false], [$mar->plan, $mar->fact, $mar->diff, $mar->percent, $mar->significant]);

        $q1 = $this->row($view, self::REVENUE_ID)->cells['2027-Q1'];
        self::assertSame(['1000.00', '1150.00', '150.00', true], [$q1->plan, $q1->fact, $q1->diff, $q1->significant]);

        $ytd = $this->row($view, self::RENT_ID)->cells[BudgetPlanFactColumn::YTD];
        self::assertSame(['-300.00', '-310.00', '-10.00'], [$ytd->plan, $ytd->fact, $ytd->diff]);

        // KPI квартала — формула от сумм квартала (700 / 1000), а не сумма месячных KPI.
        self::assertSame('0.7000', $this->row($view, self::MARGIN_ID)->cells['2027-Q1']->plan);

        $year = $this->row($view, self::REVENUE_ID)->cells['total'];
        self::assertSame(['1000.00', null, null], [$year->plan, $year->fact, $year->diff]);
    }

    private function build(?string $projectId, ?string $centerId): BudgetPlanFactView
    {
        $version = $this->em->find(BudgetVersion::class, self::VERSION_ID);
        self::assertNotNull($version);

        return self::getContainer()->get(BudgetPlanFactBuilder::class)->build($version, $projectId, $centerId);
    }

    /**
     * @param list<string> $projects
     * @param list<string> $centers
     */
    private function filter(array $projects, array $centers): BudgetPlanFactFilter
    {
        return self::getContainer()->get(BudgetPlanFactFilterResolver::class)->resolve(self::COMPANY_ID, $projects, $centers);
    }

    private function buildFiltered(BudgetPlanFactFilter $filter): BudgetPlanFactView
    {
        $version = $this->em->find(BudgetVersion::class, self::VERSION_ID);
        self::assertNotNull($version);

        return self::getContainer()->get(BudgetPlanFactBuilder::class)->buildFiltered($version, $filter);
    }

    private function childProject(string $parentId, string $name): string
    {
        $company = $this->em->getReference(Company::class, self::COMPANY_ID);
        $parent = $this->em->find(ProjectDirection::class, $parentId);
        self::assertNotNull($company);
        self::assertNotNull($parent);
        $child = new ProjectDirection(sprintf('0193cccc-0000-7000-8000-0000000f9%03d', ++$this->n), $company, $name);
        $child->setParent($parent);
        $this->em->persist($child);

        return (string) $child->getId();
    }

    private function row(BudgetPlanFactView $view, string $id): BudgetPlanFactRow
    {
        foreach ($view->rows as $row) {
            if ($row->id === $id) {
                return $row;
            }
        }
        self::fail('row not found: '.$id);
    }

    private function line(BudgetVersion $version, string $categoryId, string $month, string $amount, string $projectId, string $centerId): void
    {
        $this->em->persist(BudgetLineBuilder::aLine()->withId(sprintf('0193cccc-0000-7000-8000-0000000f7%03d', ++$this->n))->inVersion($version)->forCategory($categoryId)->forMonth($month)->forPair($projectId, $centerId)->withAmount($amount)->build());
    }

    private function fact(string $categoryId, string $projectId, ?string $centerId, string $date, string $income = '0', string $expense = '0'): void
    {
        $company = $this->em->getReference(Company::class, self::COMPANY_ID);
        $project = $this->em->getReference(ProjectDirection::class, $projectId);
        self::assertNotNull($company);
        self::assertNotNull($project);
        $total = new PLDailyTotal(
            sprintf('0193cccc-0000-7000-8000-0000000f8%03d', ++$this->n),
            $company,
            $project,
            new \DateTimeImmutable($date),
            $this->em->getReference(PLCategory::class, $categoryId),
        );
        $total->setAmountIncome($income);
        $total->setAmountExpense($expense);
        $total->setResponsibilityCenterId($centerId);
        $this->em->persist($total);
    }
}

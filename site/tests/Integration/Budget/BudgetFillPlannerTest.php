<?php

declare(strict_types=1);

namespace App\Tests\Integration\Budget;

use App\Budget\Application\DTO\BudgetFillRequest;
use App\Budget\Application\Service\BudgetFillPlanner;
use App\Budget\Application\View\BudgetFillPlan;
use App\Budget\Entity\BudgetVersion;
use App\Budget\Enum\BudgetFillHelper;
use App\Budget\Enum\BudgetLineSource;
use App\Budget\Enum\BudgetVersionStatus;
use App\Budget\Exception\BudgetVersionNotEditableException;
use App\Budget\Exception\InvalidBudgetInputException;
use App\Budget\Exception\InvalidBudgetPairException;
use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Finance\Entity\PLCategory;
use App\Finance\Entity\PLDailyTotal;
use App\Tests\Builders\Budget\BudgetLineBuilder;
use App\Tests\Builders\Budget\BudgetVersionBuilder;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Помощники заполнения плана: каждый помощник, только выбранные статьи и месяцы, sales_plan не
 * трогается, комментарий сохраняется, перезапись считается.
 */
final class BudgetFillPlannerTest extends BudgetIntegrationTestCase
{
    use ClockSensitiveTrait;

    private const VERSION_ID = '0193cccc-0000-7000-8000-0000000f9001';
    private const APPROVED_ID = '0193cccc-0000-7000-8000-0000000f9002';

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime(new \DateTimeImmutable('2027-03-15 12:00', new \DateTimeZone('Europe/Moscow')));
        $version = BudgetVersionBuilder::aVersion()->withId(self::VERSION_ID)->forCompany(self::COMPANY_ID)->withYear(2027)->build();
        $approved = BudgetVersionBuilder::aVersion()->withId(self::APPROVED_ID)->forCompany(self::COMPANY_ID)->withYear(2028)->withStatus(BudgetVersionStatus::APPROVED)->build();
        $this->em->persist($version);
        $this->em->persist($approved);
        $this->line($version, self::REVENUE_ID, '2027-01', '1000.00');
        $this->line($version, self::RENT_ID, '2027-02', '-300.00', comment: 'аренда офиса');
        $this->line($version, self::REVENUE_ID, '2027-05', '7777.00', source: BudgetLineSource::SALES_PLAN);

        $this->fact(self::REVENUE_ID, '2026-01-10', income: '500.00');
        $this->fact(self::REVENUE_ID, '2026-12-10', income: '300.00');
        $this->fact(self::REVENUE_ID, '2027-01-10', income: '600.00');
        $this->fact(self::RENT_ID, '2027-02-10', expense: '90.00');
        $this->fact(self::REVENUE_ID, '2027-03-10', income: '9999.00');
        $this->em->flush();
        $this->em->clear();
    }

    public function testLastYearFactTakesSameMonthOfPreviousYear(): void
    {
        $plan = $this->plan(BudgetFillHelper::LAST_YEAR_FACT, [self::REVENUE_ID], ['2027-01', '2027-02']);

        // 2027-01: 1000 → 500 (перезапись), 2027-02: факта нет, ячейка пустая — не меняется.
        self::assertSame([[self::REVENUE_ID, '2027-01', '500.00']], $this->cells($plan));
        self::assertSame([1, 1, 0], [$plan->changed, $plan->overwrite, $plan->skipped]);
    }

    public function testAverageOfLastClosedMonths(): void
    {
        $plan = $this->plan(BudgetFillHelper::AVERAGE, [self::REVENUE_ID, self::RENT_ID], ['2027-04'], ['months' => 3]);

        // Закрыты 2026-12, 2027-01, 2027-02 (март идёт): выручка (300 + 600 + 0) / 3, аренда −90 / 3.
        self::assertSame([[self::REVENUE_ID, '2027-04', '300.00'], [self::RENT_ID, '2027-04', '-30.00']], $this->cells($plan));
    }

    public function testDistributeSignsExpenseAndKeepsComment(): void
    {
        $plan = $this->plan(BudgetFillHelper::DISTRIBUTE, [self::RENT_ID], [], ['amount' => '1200']);

        self::assertCount(12, $plan->cells);
        self::assertSame([12, 1], [$plan->changed, $plan->overwrite]);
        $feb = $plan->cells[1];
        self::assertSame(['2027-02', '-100.00', 'аренда офиса'], [$feb->month, $feb->amount, $feb->comment]);
    }

    public function testCopyRightSkipsSalesPlan(): void
    {
        $plan = $this->plan(BudgetFillHelper::COPY_RIGHT, [self::REVENUE_ID], [], ['sourceMonth' => '2027-01']);

        self::assertCount(10, $plan->cells);
        self::assertSame(['2027-02', '1000.00'], [$plan->cells[0]->month, $plan->cells[0]->amount]);
        self::assertNotContains('2027-05', array_map(static fn ($c): string => $c->month, $plan->cells));
        self::assertSame(1, $plan->skipped);
    }

    public function testPercentOfBaseRowSignsByTargetFlow(): void
    {
        $plan = $this->plan(BudgetFillHelper::PERCENT_OF, [self::RENT_ID], ['2027-01'], ['percent' => '10', 'baseCategoryId' => self::REVENUE_ID]);

        self::assertSame([[self::RENT_ID, '2027-01', '-100.00']], $this->cells($plan));
    }

    public function testPercentOfTotalExcludesTargetsSoRepeatGivesSameAmounts(): void
    {
        // База — итог «Прибыль» (выручка + аренда), цель — аренда: аренда в базу не входит,
        // 10% от 1000 = −100, и после записи −100 повтор считает то же самое.
        $plan = $this->plan(BudgetFillHelper::PERCENT_OF, [self::RENT_ID], ['2027-01', '2027-02'], ['percent' => '10', 'baseCategoryId' => self::SUBTOTAL_ID]);

        self::assertSame([[self::RENT_ID, '2027-01', '-100.00'], [self::RENT_ID, '2027-02', '0.00']], $this->cells($plan));
    }

    public function testDistributeKeepsEnteredSignForIncome(): void
    {
        $plan = $this->plan(BudgetFillHelper::DISTRIBUTE, [self::REVENUE_ID], [], ['amount' => '-1200']);

        self::assertSame('-100.00', $plan->cells[1]->amount);
    }

    public function testRejectsNonLeafCategoryBadParamsMissingPairAndApprovedVersion(): void
    {
        foreach ([
            [BudgetFillHelper::DISTRIBUTE, [self::SUBTOTAL_ID], ['amount' => '100'], InvalidBudgetInputException::class],
            [BudgetFillHelper::DISTRIBUTE, [self::RENT_ID], ['amount' => 'abc'], InvalidBudgetInputException::class],
            [BudgetFillHelper::AVERAGE, [self::RENT_ID], ['months' => 5], InvalidBudgetInputException::class],
            [BudgetFillHelper::PERCENT_OF, [self::RENT_ID], ['percent' => '10', 'baseCategoryId' => self::OTHER_COMPANY_LEAF_ID], InvalidBudgetInputException::class],
            [BudgetFillHelper::PERCENT_OF, [self::RENT_ID], ['percent' => '10', 'baseCategoryId' => self::RENT_ID], InvalidBudgetInputException::class],
        ] as [$helper, $categories, $params, $exception]) {
            try {
                $this->plan($helper, $categories, ['2027-01'], $params);
                self::fail('expected '.$exception);
            } catch (\RuntimeException $e) {
                self::assertInstanceOf($exception, $e);
            }
        }

        $this->expectException(InvalidBudgetPairException::class);
        $this->planner()->plan($this->version(self::VERSION_ID), new BudgetFillRequest(BudgetFillHelper::DISTRIBUTE, '', '', [self::RENT_ID], [], ['amount' => '1']));
    }

    public function testApprovedVersionIsNotEditable(): void
    {
        $this->expectException(BudgetVersionNotEditableException::class);
        $this->planner()->plan($this->version(self::APPROVED_ID), new BudgetFillRequest(BudgetFillHelper::DISTRIBUTE, self::GENERAL_PROJECT_ID, $this->generalCenterId, [self::RENT_ID], [], ['amount' => '1']));
    }

    /**
     * @param list<string> $categoryIds
     * @param list<string> $months
     * @param array<string, mixed> $params
     */
    private function plan(BudgetFillHelper $helper, array $categoryIds, array $months, array $params = []): BudgetFillPlan
    {
        return $this->planner()->plan($this->version(self::VERSION_ID), new BudgetFillRequest($helper, self::GENERAL_PROJECT_ID, $this->generalCenterId, $categoryIds, $months, $params));
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function cells(BudgetFillPlan $plan): array
    {
        return array_map(static fn ($c): array => [$c->plCategoryId, $c->month, $c->amount], $plan->cells);
    }

    private function planner(): BudgetFillPlanner
    {
        return self::getContainer()->get(BudgetFillPlanner::class);
    }

    private function version(string $id): BudgetVersion
    {
        $version = $this->em->find(BudgetVersion::class, $id);
        self::assertNotNull($version);

        return $version;
    }

    private function line(BudgetVersion $version, string $categoryId, string $month, string $amount, ?string $comment = null, BudgetLineSource $source = BudgetLineSource::MANUAL): void
    {
        $this->em->persist(BudgetLineBuilder::aLine()->withId(sprintf('0193cccc-0000-7000-8000-0000000f9%03d', 100 + ++$this->n))->inVersion($version)->forCategory($categoryId)->forMonth($month)->forPair(self::GENERAL_PROJECT_ID, $this->generalCenterId)->withAmount($amount)->withComment($comment)->withSource($source)->build());
    }

    private function fact(string $categoryId, string $date, string $income = '0', string $expense = '0'): void
    {
        $company = $this->em->getReference(Company::class, self::COMPANY_ID);
        $project = $this->em->getReference(ProjectDirection::class, self::GENERAL_PROJECT_ID);
        $category = $this->em->getReference(PLCategory::class, $categoryId);
        self::assertNotNull($company);
        self::assertNotNull($project);
        $total = new PLDailyTotal(sprintf('0193cccc-0000-7000-8000-0000000f9%03d', 500 + ++$this->n), $company, $project, new \DateTimeImmutable($date), $category);
        $total->setAmountIncome($income);
        $total->setAmountExpense($expense);
        $total->setResponsibilityCenterId($this->generalCenterId);
        $this->em->persist($total);
    }
}

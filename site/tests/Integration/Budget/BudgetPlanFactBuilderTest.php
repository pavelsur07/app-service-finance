<?php

declare(strict_types=1);

namespace App\Tests\Integration\Budget;

use App\Budget\Application\Service\BudgetPlanFactBuilder;
use App\Budget\Application\View\BudgetPlanFactRow;
use App\Budget\Application\View\BudgetPlanFactView;
use App\Budget\Entity\BudgetVersion;
use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Finance\Entity\PLCategory;
use App\Finance\Entity\PLDailyTotal;
use App\Tests\Builders\Budget\BudgetLineBuilder;
use App\Tests\Builders\Budget\BudgetVersionBuilder;

final class BudgetPlanFactBuilderTest extends BudgetIntegrationTestCase
{
    private const VERSION_ID = '0193cccc-0000-7000-8000-0000000f6001';

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
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

        $year = $this->row($view, self::REVENUE_ID)->cells['total'];
        self::assertSame(['1000.00', '1200.00'], [$year->plan, $year->fact]);

        $profitJan = $this->row($view, self::SUBTOTAL_ID)->cells['2027-01'];
        self::assertSame(['1000.00', '1150.00'], [$profitJan->plan, $profitJan->fact]);
        self::assertCount(13, $this->row($view, self::SUBTOTAL_ID)->cells);
    }

    public function testWithoutPairWholeCompany(): void
    {
        $jan = $this->row($this->build(null, null), self::REVENUE_ID)->cells['2027-01'];

        self::assertSame(['1200.00', '1250.00'], [$jan->plan, $jan->fact]);
    }

    private function build(?string $projectId, ?string $centerId): BudgetPlanFactView
    {
        $version = $this->em->find(BudgetVersion::class, self::VERSION_ID);
        self::assertNotNull($version);

        return self::getContainer()->get(BudgetPlanFactBuilder::class)->build($version, $projectId, $centerId);
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

    private function fact(string $categoryId, string $projectId, string $centerId, string $date, string $income = '0', string $expense = '0'): void
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

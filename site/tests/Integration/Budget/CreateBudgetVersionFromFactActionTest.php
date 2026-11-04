<?php

declare(strict_types=1);

namespace App\Tests\Integration\Budget;

use App\Budget\Application\CreateBudgetVersionFromFactAction;
use App\Budget\Application\DTO\CreateBudgetVersionFromFactCommand;
use App\Budget\Application\View\BudgetFactFillResult;
use App\Budget\Exception\InvalidBudgetInputException;
use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Finance\Entity\PLCategory;
use App\Finance\Entity\PLDailyTotal;

/**
 * «Заполнить из факта {год−1}»: новый черновик года, в каждой паре — факт того же месяца
 * прошлого года (знак отчёта, «Общий» с фактом без ЦФО); повтор не дублирует; без факта — пустой.
 */
final class CreateBudgetVersionFromFactActionTest extends BudgetIntegrationTestCase
{
    private const VERSION_ID = '0193cccc-0000-7000-8000-0000000fa001';

    private int $n = 0;

    public function testFillsEveryPairWithSameMonthFactOfPreviousYear(): void
    {
        $this->fact(self::REVENUE_ID, '2026-01-10', self::GENERAL_PROJECT_ID, $this->generalCenterId, income: '500.00');
        $this->fact(self::REVENUE_ID, '2026-01-20', self::GENERAL_PROJECT_ID, null, income: '25.00');
        $this->fact(self::RENT_ID, '2026-03-05', self::GENERAL_PROJECT_ID, $this->generalCenterId, expense: '90.00');
        $this->fact(self::REVENUE_ID, '2026-02-01', self::SHOP_PROJECT_ID, $this->salesCenterId, income: '70.00');
        $this->fact(self::REVENUE_ID, '2027-01-10', self::GENERAL_PROJECT_ID, $this->generalCenterId, income: '9999.00');
        $this->em->flush();
        $this->em->clear();

        $result = $this->create();

        self::assertTrue($result->created);
        self::assertSame(3, $result->cells);
        self::assertSame(2, $result->pairs);
        self::assertSame([
            [self::REVENUE_ID, '2027-01', self::GENERAL_PROJECT_ID, $this->generalCenterId, '525.00'],
            [self::REVENUE_ID, '2027-02', self::SHOP_PROJECT_ID, $this->salesCenterId, '70.00'],
            [self::RENT_ID, '2027-03', self::GENERAL_PROJECT_ID, $this->generalCenterId, '-90.00'],
        ], $this->rows());
        self::assertSame('draft', $this->connection->fetchOne('SELECT status FROM budget_versions WHERE id = ?', [self::VERSION_ID]));

        $repeat = $this->create();
        self::assertFalse($repeat->created, 'повтор с тем же ключом — тот же вариант');
        self::assertSame(3, $this->countRows('budget_lines', 'version_id = ?', [self::VERSION_ID]));
        self::assertSame(1, $this->countRows('budget_versions', 'id = ?', [self::VERSION_ID]));
    }

    public function testWithoutFactCreatesEmptyDraft(): void
    {
        $result = $this->create();

        self::assertTrue($result->created);
        self::assertSame(0, $result->cells);
        self::assertSame(0, $this->countRows('budget_lines', 'version_id = ?', [self::VERSION_ID]));
    }

    public function testBlankNameIsRejectedWithoutWrites(): void
    {
        $this->expectException(InvalidBudgetInputException::class);
        try {
            ($this->action())(new CreateBudgetVersionFromFactCommand(self::VERSION_ID, self::COMPANY_ID, '   ', 2027));
        } finally {
            self::assertSame(0, $this->countRows('budget_versions', 'id = ?', [self::VERSION_ID]));
        }
    }

    private function create(): BudgetFactFillResult
    {
        return ($this->action())(new CreateBudgetVersionFromFactCommand(self::VERSION_ID, self::COMPANY_ID, 'Из факта', 2027));
    }

    private function action(): CreateBudgetVersionFromFactAction
    {
        return self::getContainer()->get(CreateBudgetVersionFromFactAction::class);
    }

    /**
     * @return list<array{string, string, string, string, string}>
     */
    private function rows(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT pl_category_id, month, project_direction_id, responsibility_center_id, amount FROM budget_lines WHERE version_id = ? ORDER BY month, pl_category_id',
            [self::VERSION_ID],
        );

        return array_map(static fn (array $r): array => [(string) $r['pl_category_id'], substr((string) $r['month'], 0, 7), (string) $r['project_direction_id'], (string) $r['responsibility_center_id'], (string) $r['amount']], $rows);
    }

    private function fact(string $categoryId, string $date, string $projectId, ?string $centerId, string $income = '0', string $expense = '0'): void
    {
        $company = $this->em->getReference(Company::class, self::COMPANY_ID);
        $project = $this->em->getReference(ProjectDirection::class, $projectId);
        $category = $this->em->getReference(PLCategory::class, $categoryId);
        self::assertNotNull($company);
        self::assertNotNull($project);
        $total = new PLDailyTotal(sprintf('0193cccc-0000-7000-8000-0000000fa%03d', 100 + ++$this->n), $company, $project, new \DateTimeImmutable($date), $category);
        $total->setAmountIncome($income);
        $total->setAmountExpense($expense);
        $total->setResponsibilityCenterId($centerId);
        $this->em->persist($total);
    }
}

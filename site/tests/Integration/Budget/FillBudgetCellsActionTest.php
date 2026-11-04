<?php

declare(strict_types=1);

namespace App\Tests\Integration\Budget;

use App\Budget\Application\DTO\BudgetFillRequest;
use App\Budget\Application\DTO\FillBudgetCellsCommand;
use App\Budget\Application\FillBudgetCellsAction;
use App\Budget\Application\View\BudgetFillPlan;
use App\Budget\Enum\BudgetFillHelper;
use App\Tests\Builders\Budget\BudgetLineBuilder;
use App\Tests\Builders\Budget\BudgetVersionBuilder;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;

/**
 * Запись помощника: расчёт и запись под одной блокировкой варианта; повтор — те же суммы.
 */
final class FillBudgetCellsActionTest extends BudgetIntegrationTestCase
{
    private const VERSION_ID = '0193cccc-0000-7000-8000-0000000fa001';

    protected function setUp(): void
    {
        parent::setUp();
        $version = BudgetVersionBuilder::aVersion()->withId(self::VERSION_ID)->forCompany(self::COMPANY_ID)->withYear(2027)->build();
        $this->em->persist($version);
        $this->em->persist(BudgetLineBuilder::aLine()->withId('0193cccc-0000-7000-8000-0000000fa101')->inVersion($version)->forCategory(self::REVENUE_ID)->forMonth('2027-01')->forPair(self::GENERAL_PROJECT_ID, $this->generalCenterId)->withAmount('1000.00')->build());
        $this->em->flush();
        $this->em->clear();
    }

    public function testRepeatOfPercentOfTotalChangesNothing(): void
    {
        $first = $this->apply(['percent' => '10', 'baseCategoryId' => self::SUBTOTAL_ID]);
        $repeat = $this->apply(['percent' => '10', 'baseCategoryId' => self::SUBTOTAL_ID]);

        self::assertSame(1, $first->changed);
        self::assertSame(0, $repeat->changed);
        self::assertSame('-100.00', $this->connection->fetchOne('SELECT amount FROM budget_lines WHERE version_id = ? AND pl_category_id = ?', [self::VERSION_ID, self::RENT_ID]));
    }

    public function testApplyWaitsForVersionLockBeforeReadingCells(): void
    {
        $worker = DriverManager::getConnection($this->connection->getParams());
        try {
            $worker->beginTransaction();
            $worker->executeStatement('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))', ['key' => 'budget_version:'.self::VERSION_ID]);
            $this->connection->executeStatement("SET lock_timeout = '300ms'");

            try {
                $this->apply(['percent' => '10', 'baseCategoryId' => self::SUBTOTAL_ID]);
                self::fail('fill must wait for the version lock');
            } catch (DriverException $exception) {
                self::assertSame('55P03', $exception->getSQLState(), 'lock timeout');
            }
        } finally {
            $this->connection->executeStatement('SET lock_timeout = 0');
            $worker->rollBack();
            $worker->close();
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function apply(array $params): BudgetFillPlan
    {
        $request = new BudgetFillRequest(BudgetFillHelper::PERCENT_OF, self::GENERAL_PROJECT_ID, $this->generalCenterId, [self::RENT_ID], ['2027-01'], $params);

        return self::getContainer()->get(FillBudgetCellsAction::class)(new FillBudgetCellsCommand(self::COMPANY_ID, self::VERSION_ID, $request, true, true));
    }
}

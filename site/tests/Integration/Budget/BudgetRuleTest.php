<?php

declare(strict_types=1);

namespace App\Tests\Integration\Budget;

use App\Budget\Application\CreateBudgetVersionAction;
use App\Budget\Application\DTO\BudgetCellInput;
use App\Budget\Application\DTO\BudgetFillRequest;
use App\Budget\Application\DTO\BudgetPlanFile;
use App\Budget\Application\DTO\BudgetPlanFileRow;
use App\Budget\Application\DTO\CreateBudgetVersionCommand;
use App\Budget\Application\DTO\FillBudgetCellsCommand;
use App\Budget\Application\DTO\ImportBudgetCellsCommand;
use App\Budget\Application\DTO\RemoveBudgetRuleCommand;
use App\Budget\Application\DTO\SaveBudgetLinesCommand;
use App\Budget\Application\DTO\SetBudgetRuleCommand;
use App\Budget\Application\FillBudgetCellsAction;
use App\Budget\Application\ImportBudgetCellsAction;
use App\Budget\Application\RemoveBudgetRuleAction;
use App\Budget\Application\SaveBudgetLinesAction;
use App\Budget\Application\Service\BudgetGridBuilder;
use App\Budget\Application\SetBudgetRuleAction;
use App\Budget\Entity\BudgetVersion;
use App\Budget\Enum\BudgetFillHelper;
use App\Budget\Enum\BudgetVersionStatus;
use App\Budget\Exception\BudgetVersionNotEditableException;
use App\Budget\Exception\InvalidBudgetInputException;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLFlow;
use App\Tests\Builders\Budget\BudgetVersionBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;

/**
 * Правило «X % от строки-базы»: заполнение, пересчёт при правке базы любым путём, защита от
 * ручной записи, запреты цепочек, снятие, копия варианта, сетка.
 */
final class BudgetRuleTest extends BudgetIntegrationTestCase
{
    private const VERSION_ID = '0193cccc-0000-7000-8000-0000000fb001';
    private const APPROVED_ID = '0193cccc-0000-7000-8000-0000000fb002';

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->persist(BudgetVersionBuilder::aVersion()->withId(self::VERSION_ID)->forCompany(self::COMPANY_ID)->withYear(2027)->build());
        $this->em->persist(BudgetVersionBuilder::aVersion()->withId(self::APPROVED_ID)->forCompany(self::COMPANY_ID)->withYear(2028)->withStatus(BudgetVersionStatus::APPROVED)->build());
        $this->em->flush();
        $this->em->clear();
        $this->save([[self::REVENUE_ID, '2027-01', '1000.00'], [self::REVENUE_ID, '2027-02', '2000.05']]);
    }

    public function testRuleFillsMonthsWithExpenseSignAndRepeatChangesNothing(): void
    {
        self::assertSame(2, $this->setRule(self::RENT_ID, self::REVENUE_ID, '12.5'));

        // 12,5 % от 2000,05 = 250,00625 → 250,01; расход — с минусом; месяцы без выручки — без строк.
        self::assertSame([['2027-01', '-125.00', 'rule'], ['2027-02', '-250.01', 'rule']], $this->rentLines());
        self::assertSame(0, $this->setRule(self::RENT_ID, self::REVENUE_ID, '12.5'));
        self::assertSame(1, $this->countRows('budget_rules'));
    }

    public function testTotalAsBaseExcludesRuleTargets(): void
    {
        // «Прибыль» = выручка + аренда; аренда с правилом в базу не входит — 10 % от выручки.
        $this->setRule(self::RENT_ID, self::SUBTOTAL_ID, '10');

        self::assertSame([['2027-01', '-100.00', 'rule'], ['2027-02', '-200.01', 'rule']], $this->rentLines());
    }

    public function testBaseChangedBySaveFillOrImportRecalculatesRule(): void
    {
        $this->setRule(self::RENT_ID, self::REVENUE_ID, '10');

        $this->save([[self::REVENUE_ID, '2027-01', '4000.00'], [self::REVENUE_ID, '2027-02', '0']]);
        self::assertSame([['2027-01', '-400.00', 'rule']], $this->rentLines(), 'ноль базы удаляет строку правила');

        $plan = $this->fill(BudgetFillHelper::DISTRIBUTE, [self::REVENUE_ID, self::RENT_ID], ['amount' => '1200']);
        self::assertSame(12, $plan->changed, 'аренда с правилом пропущена помощником');
        self::assertSame(12, $plan->skipped);
        self::assertCount(12, $this->rentLines());
        self::assertSame(['2027-03', '-10.00', 'rule'], $this->rentLines()[2]);

        $amounts = array_fill(1, 12, '0.00');
        $amounts[3] = '500.00';
        $import = $this->import([new BudgetPlanFileRow(7, self::REVENUE_ID, null, 'Выручка', $amounts), new BudgetPlanFileRow(8, self::RENT_ID, null, 'Аренда', $amounts)]);
        self::assertSame(12, $import->fill->changed);
        self::assertSame([['2027-03', '-50.00', 'rule']], $this->rentLines());
    }

    public function testManualWriteToRuleTargetIsRejected(): void
    {
        $this->setRule(self::RENT_ID, self::REVENUE_ID, '10');

        try {
            $this->save([[self::RENT_ID, '2027-05', '-1.00']]);
            self::fail('ручная запись в статью с правилом должна отклоняться');
        } catch (InvalidBudgetInputException $e) {
            self::assertStringContainsString('считается по правилу', $e->getMessage());
        }
        // Проверка идёт под блокировкой варианта, отказ возвращается из транзакции: EM открыт.
        self::assertTrue($this->em->isOpen());
        self::assertSame([['2027-01', '-100.00', 'rule'], ['2027-02', '-200.01', 'rule']], $this->rentLines());
    }

    public function testRemovedRuleTargetReturnsToBaseOfOtherRules(): void
    {
        $commissionId = '0193cccc-0000-7000-8000-00000000d004';
        $parent = $this->em->find(PLCategory::class, self::SUBTOTAL_ID);
        self::assertNotNull($parent);
        $commission = PLCategoryBuilder::aPLCategory()->withId($commissionId)->forCompany($parent->getCompany())->withName('Комиссия')->withCode('FEE')->withFlow(PLFlow::EXPENSE)->withParent($parent)->build();
        $commission->setSortOrder(2);
        $this->em->persist($commission);
        $this->em->flush();
        $this->em->clear();

        $this->setRule(self::RENT_ID, self::REVENUE_ID, '10');
        $this->setRule($commissionId, self::SUBTOTAL_ID, '10');
        self::assertSame([['2027-01', '-100.00'], ['2027-02', '-200.01']], $this->amounts($commissionId), 'аренда с правилом вне базы');

        $this->removeRule(self::RENT_ID);

        // База «Прибыль» = 1000 − 100 и 2000,05 − 200,01: комиссия пересчитана при снятии.
        self::assertSame([['2027-01', '-90.00'], ['2027-02', '-180.00']], $this->amounts($commissionId));
    }

    public function testCopyWaitsForSourceVersionLock(): void
    {
        // Источник — отдельный черновик: блокировку VERSION_ID держит транзакция теста.
        $sourceId = '0193cccc-0000-7000-8000-0000000fb005';
        $this->em->persist(BudgetVersionBuilder::aVersion()->withId($sourceId)->forCompany(self::COMPANY_ID)->withYear(2027)->build());
        $this->em->flush();
        $worker = DriverManager::getConnection($this->connection->getParams());
        try {
            $worker->executeStatement("SET lock_timeout = '2s'");
            $worker->beginTransaction();
            $worker->executeStatement('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))', ['key' => 'budget_version:'.$sourceId]);
            $this->connection->executeStatement("SET lock_timeout = '300ms'");

            try {
                self::getContainer()->get(CreateBudgetVersionAction::class)(new CreateBudgetVersionCommand('0193cccc-0000-7000-8000-0000000fb006', self::COMPANY_ID, 'Копия', copyFromVersionId: $sourceId));
                self::fail('копия должна ждать блокировку источника');
            } catch (DriverException $exception) {
                self::assertSame('55P03', $exception->getSQLState(), 'lock timeout');
            }
        } finally {
            $this->connection->executeStatement('SET lock_timeout = 0');
            $worker->rollBack();
            $worker->close();
        }
    }

    public function testChainsSelfBaseZeroPercentAndApprovedAreRejected(): void
    {
        $this->setRule(self::RENT_ID, self::REVENUE_ID, '10');

        foreach ([
            [self::REVENUE_ID, self::RENT_ID, '5', 'База сама считается по правилу'],
            [self::REVENUE_ID, self::SUBTOTAL_ID, '5', 'От этой статьи считается другое правило'],
            [self::RENT_ID, self::RENT_ID, '5', 'от самой себя'],
            [self::RENT_ID, self::REVENUE_ID, '0', 'Процент'],
            [self::SUBTOTAL_ID, self::REVENUE_ID, '5', 'нельзя вводить план'],
        ] as [$target, $base, $percent, $message]) {
            try {
                $this->setRule($target, $base, $percent);
                self::fail('ожидался отказ: '.$message);
            } catch (InvalidBudgetInputException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }

        $this->expectException(BudgetVersionNotEditableException::class);
        $this->setRule(self::RENT_ID, self::REVENUE_ID, '10', self::APPROVED_ID);
    }

    public function testRemoveKeepsAmountsAsManualAndCopyCarriesRules(): void
    {
        $this->setRule(self::RENT_ID, self::REVENUE_ID, '10');
        $copyId = '0193cccc-0000-7000-8000-0000000fb003';
        self::getContainer()->get(CreateBudgetVersionAction::class)(new CreateBudgetVersionCommand($copyId, self::COMPANY_ID, 'Копия', copyFromVersionId: self::VERSION_ID));
        self::assertSame(1, $this->countRows('budget_rules', 'version_id = ?', [$copyId]));

        self::assertTrue($this->removeRule(self::RENT_ID));
        self::assertFalse($this->removeRule(self::RENT_ID), 'повтор — без изменений');
        self::assertSame([['2027-01', '-100.00', 'manual'], ['2027-02', '-200.01', 'manual']], $this->rentLines());
        $this->save([[self::RENT_ID, '2027-05', '-1.00']]);
        self::assertSame(1, $this->countRows('budget_rules', 'version_id = ?', [$copyId]), 'копия своё правило сохранила');
    }

    public function testGridShowsRuleAndLocksRow(): void
    {
        $this->setRule(self::RENT_ID, self::REVENUE_ID, '12.5');

        $version = $this->em->find(BudgetVersion::class, self::VERSION_ID);
        self::assertNotNull($version);
        $rows = [];
        foreach (self::getContainer()->get(BudgetGridBuilder::class)->build($version, self::GENERAL_PROJECT_ID, $this->generalCenterId)->rows as $row) {
            $rows[$row->id] = $row;
        }
        self::assertFalse($rows[self::RENT_ID]->editable);
        self::assertSame(['12.50', self::REVENUE_ID, 'Выручка'], [$rows[self::RENT_ID]->rule?->percent, $rows[self::RENT_ID]->rule?->baseCategoryId, $rows[self::RENT_ID]->rule?->baseName]);
        self::assertTrue($rows[self::REVENUE_ID]->editable);
        self::assertNull($rows[self::REVENUE_ID]->rule);
    }

    public function testSetRuleWaitsForVersionLock(): void
    {
        // Отдельный черновик: блокировку VERSION_ID держит транзакция теста (сохранение в setUp).
        $lockedId = '0193cccc-0000-7000-8000-0000000fb004';
        $this->em->persist(BudgetVersionBuilder::aVersion()->withId($lockedId)->forCompany(self::COMPANY_ID)->withYear(2027)->build());
        $this->em->flush();
        $worker = DriverManager::getConnection($this->connection->getParams());
        try {
            $worker->executeStatement("SET lock_timeout = '2s'");
            $worker->beginTransaction();
            $worker->executeStatement('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))', ['key' => 'budget_version:'.$lockedId]);
            $this->connection->executeStatement("SET lock_timeout = '300ms'");

            try {
                $this->setRule(self::RENT_ID, self::REVENUE_ID, '10', $lockedId);
                self::fail('правило должно ждать блокировку варианта');
            } catch (DriverException $exception) {
                self::assertSame('55P03', $exception->getSQLState(), 'lock timeout');
            }
        } finally {
            $this->connection->executeStatement('SET lock_timeout = 0');
            $worker->rollBack();
            $worker->close();
        }
        self::assertSame(0, $this->countRows('budget_rules', 'version_id = ?', [$lockedId]), 'ничего не записано');
    }

    private function setRule(string $target, string $base, string $percent, string $versionId = self::VERSION_ID): int
    {
        return self::getContainer()->get(SetBudgetRuleAction::class)(new SetBudgetRuleCommand(self::COMPANY_ID, $versionId, self::GENERAL_PROJECT_ID, $this->generalCenterId, $target, $base, $percent));
    }

    private function removeRule(string $target): bool
    {
        return self::getContainer()->get(RemoveBudgetRuleAction::class)(new RemoveBudgetRuleCommand(self::COMPANY_ID, self::VERSION_ID, self::GENERAL_PROJECT_ID, $this->generalCenterId, $target));
    }

    /**
     * @param list<array{string, string, string}> $cells
     */
    private function save(array $cells): void
    {
        self::getContainer()->get(SaveBudgetLinesAction::class)(new SaveBudgetLinesCommand(self::COMPANY_ID, self::VERSION_ID, array_map(
            fn (array $cell): BudgetCellInput => new BudgetCellInput($cell[0], $cell[1], self::GENERAL_PROJECT_ID, $this->generalCenterId, $cell[2], null),
            $cells,
        )));
    }

    /**
     * @param list<string> $categoryIds
     * @param array<string, mixed> $params
     */
    private function fill(BudgetFillHelper $helper, array $categoryIds, array $params): \App\Budget\Application\View\BudgetFillPlan
    {
        return self::getContainer()->get(FillBudgetCellsAction::class)(new FillBudgetCellsCommand(
            self::COMPANY_ID,
            self::VERSION_ID,
            new BudgetFillRequest($helper, self::GENERAL_PROJECT_ID, $this->generalCenterId, $categoryIds, [], $params),
            true,
            true,
        ));
    }

    /**
     * @param list<BudgetPlanFileRow> $rows
     */
    private function import(array $rows): \App\Budget\Application\View\BudgetImportPlan
    {
        return self::getContainer()->get(ImportBudgetCellsAction::class)(new ImportBudgetCellsCommand(
            self::COMPANY_ID,
            self::VERSION_ID,
            self::GENERAL_PROJECT_ID,
            $this->generalCenterId,
            new BudgetPlanFile(2027, self::GENERAL_PROJECT_ID, $this->generalCenterId, $rows),
            true,
            true,
        ));
    }

    /**
     * @return list<array{string, string}>
     */
    private function amounts(string $categoryId): array
    {
        $result = [];
        foreach ($this->lines(self::VERSION_ID) as $line) {
            if ($categoryId === $line['pl_category_id']) {
                $result[] = [substr((string) $line['month'], 0, 7), (string) $line['amount']];
            }
        }

        return $result;
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function rentLines(): array
    {
        $result = [];
        foreach ($this->lines(self::VERSION_ID) as $line) {
            if (self::RENT_ID === $line['pl_category_id']) {
                $result[] = [substr((string) $line['month'], 0, 7), (string) $line['amount'], (string) $line['source']];
            }
        }

        return $result;
    }
}

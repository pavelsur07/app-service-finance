<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Application\DTO\CashflowGroupRangeTooLarge;
use App\Cash\Application\DTO\CashflowGroupReport;
use App\Cash\Application\Report\BuildCashflowGroupReport;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Fixtures\Cash\IntragroupReportFixture;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class CashflowGroupPeriodsTest extends IntegrationTestCase
{
    use IntragroupReportFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createGroupFixture();
        $this->loadPeriodTransactions();
    }

    public function testEachMonthMatchesTheCompaniesReportIncludingSplitPairsAndBalances(): void
    {
        $report = $this->report('2026-10-01', '2026-12-31', 'month');
        $periods = $report->periods;
        self::assertNotNull($periods);
        self::assertCount(3, $periods->columns);
        [$october, $november, $december] = $periods->columns;
        self::assertSame(['10000.00', '2087283.03', '45000.00'], array_map(static fn ($c) => $c->amounts['total'], $periods->columns));
        self::assertSame(['0.00', '10000.00', '2097283.03'], array_column($periods->columns, 'opening'));
        self::assertSame(['10000.00', '2097283.03', '2142283.03'], array_column($periods->columns, 'closing'));
        self::assertSame('0.00', $october->transitNet);
        self::assertSame('-40000.00', $november->transitNet);
        self::assertSame('40000.00', $december->transitNet);
        self::assertSame('2750000.00', $november->unpairedNet);
        foreach ($periods->columns as $column) {
            $single = $this->report($column->from->format('Y-m-d'), $column->to->format('Y-m-d'));
            self::assertSame(self::amounts($single), $column->amounts);
            self::assertSame($single->withoutUnpaired, $column->withoutUnpaired);
            self::assertSame($single->reconciliation->transitNet, $column->transitNet);
            self::assertSame($single->reconciliation->unpairedNet, $column->unpairedNet);
            self::assertSame(bcadd($column->opening, $column->amounts['total'], 2), $column->closing);
        }
        self::assertSame('0.00', $periods->opening);
        self::assertSame('2142283.03', $periods->closing);
        self::assertSame(self::amounts($this->report('2026-10-01', '2026-12-31')), self::amounts($report));
        self::assertSame('0.00', $report->reconciliation->transitNet);
        self::assertSame(1, $periods->crossPairCount);
        self::assertSame('40000.00', $periods->crossPairAmount);
    }

    public function testQuarterMatchesWholeRangeAndIncludesArchivedRubOpening(): void
    {
        $this->accounts['0001']->setIsActive(false)->setOpeningBalance('51.23');
        $foreign = MoneyAccountBuilder::aMoneyAccount()->withId(Uuid::uuid7()->toString())
            ->forCompany($this->companies['Бета'])->build()->setCurrency('USD')->setOpeningBalance('9000.00');
        $this->em->persist($foreign);
        $this->em->flush();

        $report = $this->report('2026-10-01', '2026-12-31', 'quarter');
        $periods = $report->periods;
        self::assertNotNull($periods);
        self::assertCount(1, $periods->columns);
        $quarter = $periods->columns[0];
        self::assertSame('4 кв. 2026', $quarter->label);
        self::assertSame(self::amounts($report), $quarter->amounts);
        self::assertSame('51.23', $quarter->opening);
        self::assertSame('2142334.26', $quarter->closing);
        self::assertSame(0, $periods->crossPairCount);
        self::assertSame('0.00', $periods->crossPairAmount);
    }

    public function testClippedIsoWeeksAndMonthHaveIdenticalRangeTotals(): void
    {
        $weekly = $this->report('2026-11-01', '2026-11-30', 'week');
        self::assertNotNull($weekly->periods);
        $columns = $weekly->periods->columns;
        self::assertCount(6, $columns);
        self::assertSame('2026-11-01', $columns[0]->from->format('Y-m-d'));
        self::assertSame('2026-11-01', $columns[0]->to->format('Y-m-d'));
        self::assertSame('2026-11-02', $columns[1]->from->format('Y-m-d'));
        self::assertSame('2026-11-30', $columns[5]->from->format('Y-m-d'));
        self::assertSame('2026-11-30', $columns[5]->to->format('Y-m-d'));
        self::assertSame(self::amounts($this->report('2026-11-01', '2026-11-30')), self::amounts($weekly));
        self::assertSame('-40000.00', $columns[5]->transitNet);
        self::assertSame(0, $weekly->periods->crossPairCount);
    }

    public function testPartialMonthCountsOnlyTheVisibleLegWhenThePeerIsInTheSameCalendarMonth(): void
    {
        $this->addTransaction(38, '0001', '2026-10-19', '-127.33', 'ООО "Бета"', 'OP_OUT_OTHER');
        $this->addTransaction(39, '0003', '2026-10-21', '127.33', 'ООО "Альфа"', 'OP_IN_OTHER');
        $this->em->flush();
        $report = $this->report('2026-10-20', '2026-12-31', 'month');
        self::assertNotNull($report->periods);
        $october = $report->periods->columns[0];
        self::assertSame('127.33', $october->transitNet);
        self::assertSame('127.33', $october->amounts['OP_IN_OTHER']);
        self::assertSame('0.00', $october->amounts['OP_OUT_OTHER']);
        self::assertSame(1, $report->periods->crossPairCount, 'Пара октября за границей всего диапазона не входит в число межколоночных.');
    }

    public function testTenYearsUseCompleteCalendarYearsIncludingPairBetweenYears(): void
    {
        $this->addTransaction(38, '0001', '2026-12-31', '-0.01', 'ООО "Бета"', 'OP_OUT_OTHER');
        $this->addTransaction(39, '0003', '2027-01-02', '0.01', 'ООО "Альфа"', 'OP_IN_OTHER');
        $this->em->flush();
        $report = $this->report('2018-01-01', '2027-12-31', 'quarter');
        self::assertNotNull($report->periods);
        self::assertCount(10, $report->periods->columns);
        self::assertSame('year', $report->periods->calendar->group);
        $last = $report->periods->columns[9];
        self::assertSame('2142283.02', $last->opening);
        self::assertSame('0.01', $last->amounts['total']);
        self::assertSame('2142283.03', $last->closing);
        self::assertSame('0.01', $last->transitNet);
        self::assertSame('0.00', $report->periods->opening);
        self::assertSame('2142283.03', $report->periods->closing);
        self::assertSame(1, $report->periods->crossPairCount);
        self::assertSame('0.01', $report->periods->crossPairAmount);
        self::assertSame(self::amounts($this->report('2018-01-01', '2027-12-31')), self::amounts($report));
    }

    public function testThirtySevenYearsReturnRangeNoticeOnlyAfterAuthorizationAndSkipCashQueries(): void
    {
        $company = $this->companies['Альфа'];
        $user = $company->getUser();
        self::assertNotNull($user);
        $from = new \DateTimeImmutable('1990-01-01');
        $to = new \DateTimeImmutable('2026-12-31');
        $build = self::getContainer()->get(BuildCashflowGroupReport::class);
        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        $queries->reset();
        $result = $build((string) $company->getId(), $from, $to, 'week', (string) $user->getId());
        self::assertInstanceOf(CashflowGroupRangeTooLarge::class, $result);
        self::assertSame($from, $result->from);
        self::assertSame($to, $result->to);
        self::assertSame('week', $result->requestedGroup);
        $sql = implode("\n", array_column($queries->getData()['default'], 'sql'));
        self::assertStringNotContainsString('cash_transaction', $sql);
        self::assertStringNotContainsString('cashflow_categories', $sql);
        self::assertStringNotContainsString('cash_intragroup_decision', $sql);
        self::assertStringNotContainsString('money_account', $sql);

        $this->expectException(AccessDeniedException::class);
        $build((string) $company->getId(), $from, $to, 'week', Uuid::uuid7()->toString());
    }

    public function testQueryCountIsIndependentOfNumberOfColumns(): void
    {
        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        $counts = [];
        foreach (['quarter', 'week'] as $group) {
            $this->em->clear();
            $queries->reset();
            $periods = $this->report('2025-01-01', '2026-12-31', $group)->periods;
            self::assertNotNull($periods);
            self::assertCount('week' === $group ? 24 : 8, $periods->columns);
            $counts[] = count($queries->getData()['default']);
        }
        self::assertGreaterThan(0, $counts[0]);
        self::assertSame($counts[0], $counts[1]);
    }

    /** @return array<string, string> */
    private static function amounts(CashflowGroupReport $report): array
    {
        return array_column([...$report->lines, $report->unmatched, $report->totals], 'total', 'key');
    }
}

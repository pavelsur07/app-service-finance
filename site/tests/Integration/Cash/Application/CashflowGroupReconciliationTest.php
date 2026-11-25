<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Fixtures\Cash\IntragroupReportFixture;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class CashflowGroupReconciliationTest extends IntegrationTestCase
{
    use IntragroupReportFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createGroupFixture();
    }

    public function testUnpairedOperationsStayInReportWhenTheOtherCompanyHasNoInnOnFile(): void
    {
        $this->loadNovemberTransactions();
        $report = $this->report();

        self::assertSame('2127283.03', $report->totals->total);
        self::assertSame('0.00', $report->totals->elimination);
        self::assertSame([], $report->reconciliation->pairs);
        self::assertEqualsCanonicalizing(array_map(self::transactionId(...), [1, 4, 12, 15]), array_keys($report->reconciliation->unpaired));
        self::assertSame('-240000.00', $report->reconciliation->unpairedNet);
        self::assertTrue($report->reconciliation->balanced);
    }

    public function testTaxIdsEnableOnlyReciprocalPairsAndLaterImportMatchesTheMissingOutflow(): void
    {
        $this->loadNovemberTransactions();
        $this->fillMissingInns();
        $report = $this->report();

        $expectedPairs = [];
        foreach ([1 => 19, 4 => 20, 21 => 12, 24 => 15, 13 => 30] as $out => $in) {
            $expectedPairs[self::transactionId($out)] = self::transactionId($in);
        }
        self::assertEquals($expectedPairs, $report->reconciliation->pairs);
        self::assertEqualsCanonicalizing(array_map(self::transactionId(...), [22, 23]), array_keys($report->reconciliation->unpaired));
        self::assertSame('3667000.00', $report->reconciliation->income);
        self::assertSame('917000.00', $report->reconciliation->expense);
        self::assertTrue($report->reconciliation->balanced);
        self::assertSame('2750000.00', $report->reconciliation->unpairedNet);
        self::assertSame('-622716.97', $report->withoutUnpaired);
        self::assertSame('2127283.03', $report->totals->total);
        self::assertSame('0.00', $report->totals->elimination);
        foreach (['Альфа' => '2126740.21', 'Бета' => '-423.78', 'Гамма' => '966.60'] as $company => $amount) {
            self::assertSame($amount, $report->totals->amounts[$this->companies[$company]->getId()]);
        }
        $eliminations = array_column($report->lines, 'elimination', 'key');
        self::assertSame('-917000.00', $eliminations['OP_IN_OTHER']);
        self::assertSame('917000.00', $eliminations['OP_OUT_OTHER']);
        $lines = array_column($report->lines, null, 'key');
        self::assertTrue($lines['OP_IN_OTHER']->hasUnpaired);
        self::assertFalse($lines['OP_OUT_OTHER']->hasUnpaired);
        self::assertEqualsCanonicalizing(array_keys($expectedPairs), $lines['OP_IN_OTHER']->pairIds);
        self::assertEqualsCanonicalizing(array_keys($expectedPairs), $lines['OP_OUT_OTHER']->pairIds);

        $accounts = array_column($report->accounts, null, 'id');
        self::assertCount(4, $accounts);
        foreach (['0001' => ['2026-11-12', true], '0002' => ['2026-11-17', false], '0003' => ['2026-11-18', false], '0004' => ['2026-11-10', true]] as $suffix => [$date, $lagging]) {
            $account = $accounts[$this->accounts[$suffix]->getId()];
            self::assertSame($date, $account->lastDate);
            self::assertSame($lagging, $account->lagging);
            self::assertStringContainsString('****'.$suffix, $account->label);
            self::assertStringNotContainsString('4080281000000000', $account->label);
        }
        $this->addTransaction(34, '0001', '2026-11-13', '-2600000', 'ООО "Бета"', 'OP_OUT_OTHER');
        $this->em->flush();
        $updated = $this->report()->reconciliation;
        $expectedPairs[self::transactionId(34)] = self::transactionId(22);
        self::assertEquals($expectedPairs, $updated->pairs);
        self::assertSame([self::transactionId(23)], array_keys($updated->unpaired));
        self::assertSame('150000.00', $updated->unpairedNet);
        self::assertTrue($updated->balanced);
    }

    public function testPairCrossingMonthEndIsInTransitFromEitherSideAndEliminatesAcrossBothMonths(): void
    {
        $this->fillMissingInns();
        $this->addTransaction(1, '0001', '2026-11-30', '-102.34', 'ООО "Бета"', 'OP_OUT_OTHER');
        $this->addTransaction(2, '0003', '2026-12-02', '102.34', 'ООО "Альфа"', 'OP_IN_OTHER');
        $this->em->flush();

        $pair = [self::transactionId(1) => self::transactionId(2)];
        foreach ([['2026-11-01', '2026-11-30', '-102.34'], ['2026-12-01', '2026-12-31', '102.34']] as [$from, $to, $signedAmount]) {
            $report = $this->report($from, $to);
            self::assertSame($signedAmount, $report->totals->total);
            self::assertSame('0.00', $report->totals->elimination);
            self::assertSame([], $report->reconciliation->pairs);
            self::assertSame($pair, $report->reconciliation->inTransit);
            self::assertSame($signedAmount, $report->reconciliation->transitNet);
            self::assertSame([], $report->reconciliation->unpaired);
            self::assertTrue($report->reconciliation->balanced);
            self::assertFalse($report->totals->hasUnpaired);
            self::assertSame([], $report->totals->pairIds);
        }
        $full = $this->report('2026-11-01', '2026-12-31');
        self::assertSame($pair, $full->reconciliation->pairs);
        self::assertSame([], $full->reconciliation->inTransit);
        self::assertSame([], $full->reconciliation->unpaired);
        self::assertTrue($full->reconciliation->balanced);
        $eliminations = array_column($full->lines, 'elimination', 'key');
        self::assertSame('-102.34', $eliminations['OP_IN_OTHER']);
        self::assertSame('102.34', $eliminations['OP_OUT_OTHER']);
    }

    public function testAccountFreshnessSkipsDeletedOperationsAndUsesStrictThreeDayThreshold(): void
    {
        $this->addTransaction(1, '0001', '2026-11-15', '1.00', 'Внешний', 'OP_IN_CUSTOMERS');
        $this->addTransaction(2, '0003', '2026-11-18', '1.00', 'Внешний', 'OP_IN_CUSTOMERS');
        $this->addTransaction(3, '0001', '2026-11-22', '1.00', 'Внешний', 'OP_IN_CUSTOMERS');
        $this->accounts['0004']->setIsActive(false);
        $usd = MoneyAccountBuilder::aMoneyAccount()->withId(Uuid::uuid7()->toString())->forCompany($this->companies['Альфа'])
            ->withName('Валютный')->withCurrency('USD')->build();
        $this->em->persist($usd);
        $this->em->flush();
        $deleted = $this->em->find(CashTransaction::class, self::transactionId(3));
        self::assertInstanceOf(CashTransaction::class, $deleted);
        $deleted->markDeleted(null);
        $this->em->flush();

        $accounts = array_column($this->report()->accounts, null, 'id');
        self::assertCount(3, $accounts);
        self::assertArrayNotHasKey($usd->getId(), $accounts);
        self::assertArrayNotHasKey($this->accounts['0004']->getId(), $accounts);
        $threeDaysBehind = $accounts[$this->accounts['0001']->getId()];
        self::assertSame('2026-11-15', $threeDaysBehind->lastDate);
        self::assertFalse($threeDaysBehind->lagging);
        self::assertNull($accounts[$this->accounts['0002']->getId()]->lastDate);
        self::assertSame('2026-11-18', $accounts[$this->accounts['0003']->getId()]->lastDate);
    }
}

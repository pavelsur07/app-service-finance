<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Service\Import\File;

use App\Cash\Service\Import\File\CashFileDescriptionMatcher;
use PHPUnit\Framework\TestCase;

final class CashFileDescriptionMatcherTest extends TestCase
{
    public function testChangedDescriptionOffersSameOperation(): void
    {
        $plan = (new CashFileDescriptionMatcher())->plan([$this->row('r1', 'New')], [$this->candidate('t1', 'Old')]);
        self::assertSame('update_description', $plan['r1']['action']);
        self::assertSame('t1', $plan['r1']['transactionId']);
        self::assertSame('Old', $plan['r1']['oldDescription']);
    }

    public function testTechnicalDifferencesAndEmptyDescriptionDoNotOfferUpdates(): void
    {
        $matcher = new CashFileDescriptionMatcher();
        foreach ([" Text\r\nline ", '', null, '   '] as $text) {
            self::assertSame('unchanged', $matcher->plan([$this->row('r1', $text)], [$this->candidate('t1', "Text\nline")])['r1']['action']);
        }
        foreach (['text', 'Text!', 'Text  line'] as $text) {
            self::assertSame('update_description', $matcher->plan([$this->row('r1', $text)], [$this->candidate('t1', 'Text')])['r1']['action']);
        }
    }

    public function testIdenticalOperationsPreserveMultiplicityAndAllowSurplus(): void
    {
        $plan = (new CashFileDescriptionMatcher())->plan([$this->row('r1'), $this->row('r2'), $this->row('r3')], [$this->candidate('t1'), $this->candidate('t2')]);
        self::assertSame(['unchanged', 'unchanged', 'create'], array_column($plan, 'action'));
    }

    public function testExactNumberedMatchIsReservedBeforeBlankRow(): void
    {
        $rows = [$this->row('blank'), $this->row('numbered')];
        $rows[0]['docNumber'] = null;
        $candidates = [$this->candidate('t1'), $this->candidate('t2')];
        $candidates[1]['docNumber'] = '102';
        foreach ([$rows, array_reverse($rows)] as $input) {
            $plan = (new CashFileDescriptionMatcher())->plan($input, $candidates);
            self::assertSame('t1', $plan['numbered']['transactionId']);
            self::assertSame('t2', $plan['blank']['transactionId']);
        }
    }

    public function testExactTextIsReservedBeforeDescriptionUpdates(): void
    {
        $plan = (new CashFileDescriptionMatcher())->plan([$this->row('changed', 'New'), $this->row('exact', 'Old')], [$this->candidate('t1', 'Old'), $this->candidate('t2', 'Other')]);
        self::assertSame('t1', $plan['exact']['transactionId']);
        self::assertSame('t2', $plan['changed']['transactionId']);
    }

    public function testAmbiguousCandidateGroupAndCompetingRowsAreConflicts(): void
    {
        $matcher = new CashFileDescriptionMatcher();
        self::assertSame('conflict', $matcher->plan([$this->row('r1', 'New')], [$this->candidate('t1', 'Old'), $this->candidate('t2', 'Old')])['r1']['action']);
        $plan = $matcher->plan([$this->row('r1', 'New'), $this->row('r2', 'Other')], [$this->candidate('t1', 'Old')]);
        self::assertSame(['conflict', 'conflict'], array_column($plan, 'action'));
    }

    public function testDifferentDocumentIsNewButChangedFinancialFieldsConflict(): void
    {
        $matcher = new CashFileDescriptionMatcher();
        $otherDoc = $this->row('r1', 'New');
        $otherDoc['docNumber'] = '102';
        self::assertSame('create', $matcher->plan([$otherDoc], [$this->candidate('t1')])['r1']['action']);
        foreach (['amount' => '200.00', 'currency' => 'USD', 'direction' => 'INFLOW'] as $field => $value) {
            $row = $this->row('r1');
            $row[$field] = $value;
            self::assertSame('financial_mismatch', $matcher->plan([$row], [$this->candidate('t1')])['r1']['reason']);
        }
    }

    public function testRepeatedDocumentWithDifferentAmountsDoesNotHideIdenticalSurplus(): void
    {
        $other = $this->candidate('t2');
        $other['amount'] = '200.00';
        $plan = (new CashFileDescriptionMatcher())->plan([$this->row('r1'), $this->row('r2')], [$this->candidate('t1'), $other]);
        self::assertSame('unchanged', $plan['r1']['action']);
        self::assertSame('create', $plan['r2']['action']);
    }

    public function testCounterpartyMismatchAndInvalidRowsAreNotUpdates(): void
    {
        $row = $this->row('r1');
        $row['counterparty'] = 'other';
        $matcher = new CashFileDescriptionMatcher();
        self::assertSame('conflict', $matcher->plan([$row], [$this->candidate('t1')])['r1']['action']);
        $row['ok'] = false;
        self::assertSame('invalid', $matcher->plan([$row], [])['r1']['action']);
    }

    /** @return array{rowId: string, date: string, amount: string, direction: string, currency: string, docNumber: ?string, description: ?string, counterparty: ?string} */
    private function row(string $id, ?string $text = 'Old'): array
    {
        return ['rowId' => $id, 'date' => '2026-10-01', 'amount' => '100.00', 'direction' => 'OUTFLOW', 'currency' => 'RUB', 'docNumber' => '101', 'description' => $text, 'counterparty' => 'normalized-counterparty'];
    }

    /** @return array{id: string, date: string, amount: string, direction: string, currency: string, docNumber: ?string, description: ?string, counterparty: ?string} */
    private function candidate(string $id, ?string $text = 'Old'): array
    {
        $row = $this->row($id, $text);
        unset($row['rowId']);

        return ['id' => $id] + $row;
    }
}

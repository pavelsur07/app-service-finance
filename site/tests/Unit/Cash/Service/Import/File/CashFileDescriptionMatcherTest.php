<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Service\Import\File;

use App\Cash\Application\Service\Import\File\CashFileDescriptionMatcher;
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
        foreach ([" Text\r\nline ", '', null, '   ', "\u{00A0}Text\nline\u{00A0}", "\u{00A0}\u{202F}"] as $text) {
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

    public function testDifferentDocumentInFinancialBucketDoesNotHideAmountConflict(): void
    {
        $sameDocument = $this->candidate('t1');
        $sameDocument['amount'] = '200.00';
        $sameAmount = $this->candidate('t2');
        $sameAmount['docNumber'] = '102';
        $plan = (new CashFileDescriptionMatcher())->plan([$this->row('r1')], [$sameDocument, $sameAmount]);
        self::assertSame('conflict', $plan['r1']['action']);
        self::assertSame('financial_mismatch', $plan['r1']['reason']);
    }

    public function testBlankDocumentCannotStealNumberedDescriptionUpdate(): void
    {
        $blank = $this->row('blank');
        $blank['docNumber'] = null;
        $changed = $this->row('numbered', 'New');
        $numberedCandidate = $this->candidate('t1');
        $blankCandidate = $this->candidate('t2');
        $blankCandidate['docNumber'] = null;
        foreach ([[$blank, $changed], [$changed, $blank]] as $rows) {
            foreach ([[$numberedCandidate, $blankCandidate], [$blankCandidate, $numberedCandidate]] as $candidates) {
                $plan = (new CashFileDescriptionMatcher())->plan($rows, $candidates);
                self::assertContains($plan['numbered']['action'], ['update_description', 'conflict']);
                if ('update_description' === $plan['numbered']['action']) {
                    self::assertSame('t1', $plan['numbered']['transactionId']);
                }
                self::assertNotSame('t2', $plan['numbered']['transactionId']);
            }
        }
    }

    public function testWildcardCounterpartyDoesNotStealExplicitPartyUpdate(): void
    {
        $blank = $this->row('blank');
        $blank['counterparty'] = null;
        $changed = $this->row('explicit', 'New');
        $explicit = $this->candidate('t1');
        $wildcard = $this->candidate('t2');
        $wildcard['counterparty'] = null;
        foreach ([[$blank, $changed], [$changed, $blank]] as $rows) {
            foreach ([[$explicit, $wildcard], [$wildcard, $explicit]] as $candidates) {
                $plan = (new CashFileDescriptionMatcher())->plan($rows, $candidates);
                self::assertContains($plan['explicit']['action'], ['update_description', 'conflict']);
                if ('update_description' === $plan['explicit']['action']) {
                    self::assertSame('t1', $plan['explicit']['transactionId']);
                }
                self::assertNotSame('t2', $plan['explicit']['transactionId']);
            }
        }
    }

    public function testTwoWildcardRowsCannotChooseBetweenDifferentPartiesByOrder(): void
    {
        $old = $this->row('old');
        $old['counterparty'] = null;
        $new = $this->row('new', 'New');
        $new['counterparty'] = null;
        $a = $this->candidate('a');
        $b = $this->candidate('b');
        $b['counterparty'] = 'different-party';
        foreach ([[$old, $new], [$new, $old]] as $rows) {
            foreach ([[$a, $b], [$b, $a]] as $candidates) {
                $plan = (new CashFileDescriptionMatcher())->plan($rows, $candidates);
                self::assertSame('conflict', $plan['new']['action']);
                self::assertNull($plan['new']['transactionId']);
            }
        }
    }

    public function testEmptyDescriptionCannotArbitrarilyChooseTargetForChangedText(): void
    {
        $empty = $this->row('empty', '');
        $new = $this->row('new', 'New');
        foreach ([[$empty, $new], [$new, $empty]] as $rows) {
            foreach ([[$this->candidate('a'), $this->candidate('b')], [$this->candidate('b'), $this->candidate('a')]] as $candidates) {
                $plan = (new CashFileDescriptionMatcher())->plan($rows, $candidates);
                self::assertSame('conflict', $plan['new']['action']);
                self::assertNull($plan['new']['transactionId']);
            }
        }
    }

    public function testCrossedDocumentAndCounterpartyWildcardsNeverChooseAnArbitraryUpdate(): void
    {
        $old = $this->row('old');
        $old['counterparty'] = null;
        $new = $this->row('new', 'New');
        $new['docNumber'] = null;
        $a = $this->candidate('a');
        $a['counterparty'] = null;
        $b = $this->candidate('b');
        $b['docNumber'] = null;
        foreach ([[$old, $new], [$new, $old]] as $rows) {
            foreach ([[$a, $b], [$b, $a]] as $candidates) {
                $plan = (new CashFileDescriptionMatcher())->plan($rows, $candidates);
                self::assertSame('conflict', $plan['new']['action']);
                self::assertNull($plan['new']['transactionId']);
            }
        }
    }

    public function testExplicitPartyExactMatchPrecedesNullPartyFallback(): void
    {
        $old = $this->row('old');
        $new = $this->row('new', 'New');
        $new['counterparty'] = 'party-b';
        $a = $this->candidate('a');
        $unknown = $this->candidate('unknown');
        $unknown['counterparty'] = null;
        foreach ([[$old, $new], [$new, $old]] as $rows) {
            foreach ([[$a, $unknown], [$unknown, $a]] as $candidates) {
                $plan = (new CashFileDescriptionMatcher())->plan($rows, $candidates);
                self::assertSame('a', $plan['old']['transactionId']);
                self::assertSame('update_description', $plan['new']['action']);
                self::assertSame('unknown', $plan['new']['transactionId']);
            }
        }
    }

    public function testEmptyDescriptionCannotChooseAmongDifferentOriginalDescriptions(): void
    {
        $empty = $this->row('empty', '');
        $empty['counterparty'] = null;
        $changed = $this->row('changed', 'New description');
        $changed['counterparty'] = null;
        $a = $this->candidate('a', 'Old A');
        $a['counterparty'] = null;
        $b = $this->candidate('b', 'Old B');
        $b['counterparty'] = null;
        foreach ([[$empty, $changed], [$changed, $empty]] as $rows) {
            foreach ([[$a, $b], [$b, $a]] as $candidates) {
                $plan = (new CashFileDescriptionMatcher())->plan($rows, $candidates);
                self::assertSame('conflict', $plan['changed']['action']);
                self::assertNull($plan['changed']['transactionId']);
            }
        }
    }

    public function testEmptyOnlyRowsPreserveExistingQuantityAndAllowSurplus(): void
    {
        $emptyRows = [$this->row('e1', ''), $this->row('e2', null), $this->row('e3', ' ')];
        $a = $this->candidate('a', 'Old A');
        $b = $this->candidate('b', 'Old B');
        foreach ([[$a, $b], [$b, $a]] as $candidates) {
            $plan = (new CashFileDescriptionMatcher())->plan($emptyRows, $candidates);
            self::assertSame(['unchanged', 'unchanged', 'create'], array_column($plan, 'action'));
        }
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

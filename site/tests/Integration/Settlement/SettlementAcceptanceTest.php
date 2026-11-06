<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settlement;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Settlement\Application\AllocateSettlementAction;
use App\Settlement\Application\ConfigureSettlementBookAction;
use App\Settlement\Application\CreateSettlementDocumentAction;
use App\Settlement\Application\DTO\AllocateSettlementInput;
use App\Settlement\Application\DTO\CreateSettlementDocumentInput;
use App\Settlement\Application\DTO\RegisterCashSettlementInput;
use App\Settlement\Application\DTO\ReverseSettlementInput;
use App\Settlement\Application\DTO\SettlementReportFilter;
use App\Settlement\Application\DTO\SettlementReportTotals;
use App\Settlement\Application\PostSettlementDocumentAction;
use App\Settlement\Application\ReconcileSettlementRegisterAction;
use App\Settlement\Application\RegisterCashSettlementAction;
use App\Settlement\Application\ReverseSettlementAllocationAction;
use App\Settlement\Application\ReverseSettlementDocumentAction;
use App\Settlement\Enum\SettlementBucket;
use App\Settlement\Enum\SettlementDocumentKind;
use App\Settlement\Facade\SettlementFacadeInterface;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

final class SettlementAcceptanceTest extends IntegrationTestCase
{
    private Company $company;
    private Counterparty $party;
    private MoneyAccount $account;
    private string $companyId;
    private string $actorId;

    protected function setUp(): void
    {
        parent::setUp();
        // EntityManager listeners initialize the clock; use the existing Billing test pattern.
        self::ensureKernelShutdown();
        self::bootKernel();
        self::getContainer()->set(ClockInterface::class, new MockClock(new \DateTimeImmutable('2026-11-10 12:00:00', new \DateTimeZone('Europe/Moscow'))));
        $this->em = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $this->connection = $this->em->getConnection();
        $user = UserBuilder::aUser()->build();
        $this->company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $this->party = CounterpartyBuilder::aCounterparty()->withCompany($this->company)->build();
        $this->account = MoneyAccountBuilder::aMoneyAccount()->forCompany($this->company)->build();
        foreach ([$user, $this->company, $this->party, $this->account] as $entity) {
            $this->em->persist($entity);
        } $this->em->flush();
        $this->companyId = (string) $this->company->getId();
        $this->actorId = (string) $user->getId();
        (self::getContainer()->get(ConfigureSettlementBookAction::class))($this->companyId, $this->actorId, new \DateTimeImmutable('2026-11-01'));
    }

    private function debt(string $amount, string $date = '2026-11-01', SettlementBucket $bucket = SettlementBucket::PAYABLE, SettlementDocumentKind $kind = SettlementDocumentKind::OBLIGATION, ?string $dueDate = null): string
    {
        $draft = (self::getContainer()->get(CreateSettlementDocumentAction::class))($this->companyId, $this->actorId, new CreateSettlementDocumentInput(Uuid::uuid7()->toString(), $kind, $this->party->getId(), $bucket, Money::fromString($amount, 'RUB'), new \DateTimeImmutable($date), null === $dueDate ? null : new \DateTimeImmutable($dueDate), 'Приёмочный акт'));
        $posted = (self::getContainer()->get(PostSettlementDocumentAction::class))($this->companyId, $this->actorId, $draft->documentId, $draft->version);
        self::assertNotNull($posted->itemId);

        return $posted->itemId;
    }

    private function payment(string $amount, string $date, bool $advance = false, CashDirection $direction = CashDirection::OUTFLOW, bool $legacyAllocated = false): string
    {
        $cash = CashTransactionBuilder::aCashTransaction()->forCompany($this->company)->withMoneyAccount($this->account)->withAmount($amount)->withDirection($direction)->withAllocatedAmount($legacyAllocated ? $amount : '0.00')->build();
        $cash->setCounterparty($this->party)->setOccurredAt(new \DateTimeImmutable($date));
        $this->em->persist($cash);
        $this->em->flush();
        $result = (self::getContainer()->get(RegisterCashSettlementAction::class))($this->companyId, $this->actorId, new RegisterCashSettlementInput(Uuid::uuid7()->toString(), (string) $cash->getId(), $advance, new \DateTimeImmutable($date)));
        self::assertNotNull($result->itemId);

        return $result->itemId;
    }

    private function allocate(string $debt, string $payment, string $amount, string $date): string
    {
        return (self::getContainer()->get(AllocateSettlementAction::class))($this->companyId, $this->actorId, new AllocateSettlementInput(Uuid::uuid7()->toString(), $debt, $payment, Money::fromString($amount, 'RUB'), new \DateTimeImmutable($date)));
    }

    private function report(string $to): SettlementReportTotals
    {
        return self::getContainer()->get(SettlementFacadeInterface::class)->report($this->companyId, new SettlementReportFilter(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable($to), 'RUB'))->totals;
    }

    public function testA1A5DatedCancellationAndRebuildPreserveHistoricalAmounts(): void
    {
        $debt = $this->debt('100000.00', dueDate: '2026-11-05');
        $payment = $this->payment('40000.00', '2026-11-05');
        $allocation = $this->allocate($debt, $payment, '40000.00', '2026-11-05');
        (self::getContainer()->get(ReverseSettlementAllocationAction::class))($this->companyId, $this->actorId, $allocation, new ReverseSettlementInput(Uuid::uuid7()->toString(), new \DateTimeImmutable('2026-11-07'), 'Датированная отмена'));
        self::assertSame(10000000, $this->report('2026-11-04')->closing->payable->amountMinor());
        self::assertSame(0, $this->report('2026-11-04')->closing->unallocatedOut->amountMinor());
        self::assertSame(6000000, $this->report('2026-11-06')->closing->payable->amountMinor());
        self::assertSame(6000000, $this->report('2026-11-06')->overdue->amountMinor());
        self::assertSame(0, $this->report('2026-11-06')->closing->unallocatedOut->amountMinor());
        self::assertSame(10000000, $this->report('2026-11-08')->closing->payable->amountMinor());
        self::assertSame(4000000, $this->report('2026-11-08')->closing->unallocatedOut->amountMinor());
        $this->connection->executeStatement("UPDATE settlement_movements SET delta_amount_minor=-1 WHERE company_id=? AND bucket='payable' AND delta_amount_minor<0", [$this->companyId]);
        $reconcile = self::getContainer()->get(ReconcileSettlementRegisterAction::class);
        self::assertNotEmpty($reconcile($this->companyId)->differences);
        $reconcile($this->companyId, true);
        self::assertSame(6000000, $this->report('2026-11-06')->closing->payable->amountMinor());
        self::assertSame(10000000, $this->report('2026-11-08')->closing->payable->amountMinor());
        self::assertSame([], $reconcile($this->companyId)->differences);
    }

    public function testA2AdvanceBeforeLiabilityAndFinalPaymentCloseBothPositions(): void
    {
        $advance = $this->payment('40000.00', '2026-11-02', true);
        self::assertSame(4000000, $this->report('2026-11-03')->closing->advanceGiven->amountMinor());
        self::assertSame(0, $this->report('2026-11-03')->closing->payable->amountMinor());
        $debt = $this->debt('100000.00', '2026-11-04');
        $this->allocate($debt, $advance, '40000.00', '2026-11-04');
        $final = $this->payment('60000.00', '2026-11-05');
        $this->allocate($debt, $final, '60000.00', '2026-11-05');
        $result = $this->report('2026-11-05')->closing;
        self::assertSame(0, $result->payable->amountMinor());
        self::assertSame(0, $result->advanceGiven->amountMinor());
        self::assertSame(0, $result->unallocatedOut->amountMinor());
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cash_transaction WHERE company_id=?', [$this->companyId]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM documents WHERE company_id=?', [$this->companyId]));
    }

    public function testA3SeveralIncomingPaymentsLeaveReceivableFortyThousand(): void
    {
        $debt = $this->debt('120000.00', bucket: SettlementBucket::RECEIVABLE);
        $first = $this->payment('30000.00', '2026-11-02', direction: CashDirection::INFLOW);
        $this->allocate($debt, $first, '30000.00', '2026-11-02');
        $second = $this->payment('50000.00', '2026-11-03', direction: CashDirection::INFLOW);
        $this->allocate($debt, $second, '50000.00', '2026-11-03');
        $result = $this->report('2026-11-03')->closing;
        self::assertSame(4000000, $result->receivable->amountMinor());
        self::assertSame(0, $result->unallocatedIn->amountMinor());
        self::assertSame(0, $result->payable->amountMinor());
    }

    public function testA4OnePaymentClosesSeveralGroundsAndCannotBeSpentAgain(): void
    {
        $first = $this->debt('60000.00');
        $second = $this->debt('50000.00');
        $payment = $this->payment('100000.00', '2026-11-02');
        $this->allocate($first, $payment, '60000.00', '2026-11-02');
        $this->allocate($second, $payment, '40000.00', '2026-11-02');
        self::assertSame(1000000, $this->report('2026-11-02')->closing->payable->amountMinor());
        try {
            $this->allocate($second, $payment, '10000.00', '2026-11-03');
            self::fail('The payment has no free kopeck.');
        } catch (\DomainException) {
            self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM settlement_allocations WHERE company_id=?', [$this->companyId]));
        }
    }

    public function testOpeningIsSeparateFromOrdinaryTurnover(): void
    {
        $this->debt('25000.00', kind: SettlementDocumentKind::OPENING);
        $result = $this->report('2026-11-03');
        self::assertSame(2500000, $result->opening->payable->amountMinor());
        self::assertSame(0, $result->increases->payable->amountMinor());
        self::assertSame(2500000, $result->closing->payable->amountMinor());
        self::assertSame(0, $result->closing->receivable->amountMinor());
    }

    public function testA10ReinclusionKeepsOneCashAndThreeHistoricalSnapshots(): void
    {
        $item = $this->payment('40000.00', '2026-11-05');
        $doc = (string) $this->connection->fetchOne('SELECT document_id FROM settlement_items WHERE company_id=? AND id=?', [$this->companyId, $item]);
        $cash = (string) $this->connection->fetchOne('SELECT source_id FROM settlement_documents WHERE company_id=? AND id=?', [$this->companyId, $doc]);
        (self::getContainer()->get(ReverseSettlementDocumentAction::class))($this->companyId, $this->actorId, $doc, new ReverseSettlementInput(Uuid::uuid7()->toString(), new \DateTimeImmutable('2026-11-07'), 'Отмена включения'));
        (self::getContainer()->get(RegisterCashSettlementAction::class))($this->companyId, $this->actorId, new RegisterCashSettlementInput(Uuid::uuid7()->toString(), $cash, false, new \DateTimeImmutable('2026-11-08')));
        self::assertSame(4000000, $this->report('2026-11-06')->closing->unallocatedOut->amountMinor());
        self::assertSame(0, $this->report('2026-11-07')->closing->unallocatedOut->amountMinor());
        self::assertSame(4000000, $this->report('2026-11-08')->closing->unallocatedOut->amountMinor());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cash_transaction WHERE company_id=?', [$this->companyId]));
    }

    /** @return iterable<string,array{string,bool}> */
    public static function forbiddenDates(): iterable
    {
        yield 'before start' => ['2026-10-31', false];
        yield 'future' => ['2026-11-11', false];
        yield 'closed' => ['2026-11-08', true];
    }

    public function testA6MutualDebtsAreNotNetted(): void
    {
        $this->debt('50000.00');
        $this->debt('30000.00', bucket: SettlementBucket::RECEIVABLE);
        $balances = $this->report('2026-11-06')->closing;
        self::assertSame(5000000, $balances->payable->amountMinor());
        self::assertSame(3000000, $balances->receivable->amountMinor());
    }

    public function testA7FinanceGroundAndLegacyCashAllocationRemainUnchanged(): void
    {
        $finance = (new \App\Finance\Entity\Document(Uuid::uuid7()->toString(), $this->company))->setDate(new \DateTimeImmutable('2026-11-01'))->setCounterparty($this->party);
        $operation = (new \App\Finance\Entity\DocumentOperation())->setDocument($finance)->setAmount('100000.00');
        $this->em->persist($finance);
        $this->em->persist($operation);
        $this->em->flush();
        $draft = (self::getContainer()->get(\App\Settlement\Application\CreateSettlementFromFinanceAction::class))($this->companyId, $this->actorId, new \App\Settlement\Application\DTO\CreateSettlementFromFinanceInput(Uuid::uuid7()->toString(), (string) $finance->getId(), SettlementBucket::PAYABLE, Money::fromString('100000.00', 'RUB'), new \DateTimeImmutable('2026-11-01'), null, 'Акт из ОПиУ'));
        $posted = (self::getContainer()->get(PostSettlementDocumentAction::class))($this->companyId, $this->actorId, $draft->documentId, $draft->version);
        self::assertNotNull($posted->itemId);
        $payment = $this->payment('40000.00', '2026-11-05', legacyAllocated: true);
        $this->allocate($posted->itemId, $payment, '40000.00', '2026-11-05');
        self::assertSame(6000000, $this->report('2026-11-06')->closing->payable->amountMinor());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM documents WHERE company_id=?', [$this->companyId]));
        self::assertSame('100000.00', $this->connection->fetchOne('SELECT amount FROM document_operations WHERE document_id=?', [$finance->getId()]));
        self::assertSame('40000.00', $this->connection->fetchOne('SELECT allocated_amount FROM cash_transaction WHERE company_id=?', [$this->companyId]));
    }

    public function testA9BackdatedAllocationCannotSpendTheIntervalBeforeCancellation(): void
    {
        $debt = $this->debt('100000.00');
        $payment = $this->payment('100000.00', '2026-11-01');
        $allocation = $this->allocate($debt, $payment, '100000.00', '2026-11-02');
        (self::getContainer()->get(ReverseSettlementAllocationAction::class))($this->companyId, $this->actorId, $allocation, new ReverseSettlementInput(Uuid::uuid7()->toString(), new \DateTimeImmutable('2026-11-04'), 'Отмена'));
        self::assertSame(10000000, $this->report('2026-11-04')->closing->unallocatedOut->amountMinor());
        try {
            $this->allocate($debt, $payment, '100000.00', '2026-11-03');
            self::fail('The historic interval was already consumed.');
        } catch (\DomainException) {
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM settlement_allocations WHERE company_id=?', [$this->companyId]));
        }
    }

    /** @return iterable<string,array{string}> */
    public static function corruptedPrimary(): iterable
    {
        foreach (['obligation bucket', 'before cutoff', 'future', 'opening date', 'manual payment', 'payment amount', 'payment party', 'payment direction'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('corruptedPrimary')]
    public function testRebuildRefusesInvalidBaseFactsAndPreservesDerivedRows(string $case): void
    {
        $debt = $this->debt('100.00');
        $payment = $this->payment('40.00', '2026-11-01');
        $reconcile = self::getContainer()->get(ReconcileSettlementRegisterAction::class);
        self::assertSame([], $reconcile($this->companyId)->differences);
        $item = $debt;
        $fields = [];
        if ('opening date' === $case) {
            $item = $this->debt('25.00', kind: SettlementDocumentKind::OPENING);
            $fields = ['effective_date' => '2026-11-02'];
        } elseif ('obligation bucket' === $case) {
            $fields = ['bucket' => 'advance_given'];
        } elseif ('before cutoff' === $case) {
            $fields = ['effective_date' => '2026-10-31'];
        } elseif ('future' === $case) {
            $fields = ['effective_date' => '2026-11-11'];
        } else {
            $item = $payment;
            if ('payment amount' === $case) {
                $fields = ['amount_amount_minor' => 8000];
            } elseif ('payment direction' === $case) {
                $fields = ['bucket' => 'unallocated_in'];
            } elseif ('payment party' === $case) {
                $party = CounterpartyBuilder::aCounterparty()->withId(Uuid::uuid7()->toString())->withCompany($this->company)->withInn(null)->build();
                $this->em->persist($party);
                $this->em->flush();
                $fields = ['counterparty_id' => $party->getId()];
            }
        }
        $doc = (string) $this->connection->fetchOne('SELECT document_id FROM settlement_items WHERE company_id=? AND id=?', [$this->companyId, $item]);
        if ('manual payment' === $case) {
            $this->connection->update('settlement_documents', ['source_type' => 'manual', 'source_id' => null], ['company_id' => $this->companyId, 'id' => $doc]);
        } else {
            $this->connection->update('settlement_documents', $fields, ['company_id' => $this->companyId, 'id' => $doc]);
            $this->connection->update('settlement_items', $fields, ['company_id' => $this->companyId, 'id' => $item]);
        }
        $before = $this->connection->fetchAllAssociative('SELECT document_id,item_id,bucket,effective_date,delta_amount_minor FROM settlement_movements WHERE company_id=? ORDER BY document_id,item_id,bucket', [$this->companyId]);
        $links = $this->connection->fetchAllAssociative('SELECT source_type,source_id,active_document_id,last_reversal_date FROM settlement_source_links WHERE company_id=? ORDER BY source_type,source_id', [$this->companyId]);
        $this->em->clear();
        try {
            $reconcile($this->companyId, true);
            self::fail('Inconsistent primary facts cannot be rebuilt.');
        } catch (\DomainException) {
            self::assertSame($before, $this->connection->fetchAllAssociative('SELECT document_id,item_id,bucket,effective_date,delta_amount_minor FROM settlement_movements WHERE company_id=? ORDER BY document_id,item_id,bucket', [$this->companyId]));
            self::assertSame($links, $this->connection->fetchAllAssociative('SELECT source_type,source_id,active_document_id,last_reversal_date FROM settlement_source_links WHERE company_id=? ORDER BY source_type,source_id', [$this->companyId]));
        }
    }

    public function testValidHistoryCanBeRebuiltAfterItsPeriodCloses(): void
    {
        $this->debt('100.00');
        $this->payment('40.00', '2026-11-01');
        $this->connection->executeStatement("UPDATE companies SET finance_lock_before='2026-11-09' WHERE id=?", [$this->companyId]);
        $reconcile = self::getContainer()->get(ReconcileSettlementRegisterAction::class);
        self::assertSame([], $reconcile($this->companyId)->differences);
        $this->connection->executeStatement('UPDATE settlement_movements SET delta_amount_minor=1 WHERE company_id=?', [$this->companyId]);
        $reconcile($this->companyId, true);
        self::assertSame([], $reconcile($this->companyId)->differences);
        self::assertSame(10000, $this->report('2026-11-06')->closing->payable->amountMinor());
    }

    #[DataProvider('forbiddenDates')]
    public function testPostingRespectsFrozenMoscowTodayAndFreshClosure(string $date, bool $closed): void
    {
        if ($closed) {
            $this->connection->executeStatement("UPDATE companies SET finance_lock_before='2026-11-09' WHERE id=?", [$this->companyId]);
        }
        try {
            $this->debt('1.00', $date);
            self::fail('Date is outside the open financial period.');
        } catch (\DomainException) {
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM settlement_movements WHERE company_id=?', [$this->companyId]));
        }
    }
}

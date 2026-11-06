<?php

declare(strict_types=1);

namespace App\Tests\Integration\Settlement;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Settlement\Application\AllocateSettlementAction;
use App\Settlement\Application\ClassifySettlementPaymentAction;
use App\Settlement\Application\ConfigureSettlementBookAction;
use App\Settlement\Application\CreateSettlementDocumentAction;
use App\Settlement\Application\DTO\AllocateSettlementInput;
use App\Settlement\Application\DTO\CreateSettlementDocumentInput;
use App\Settlement\Application\DTO\RegisterCashSettlementInput;
use App\Settlement\Application\DTO\ReverseSettlementInput;
use App\Settlement\Application\PostSettlementDocumentAction;
use App\Settlement\Application\RegisterCashSettlementAction;
use App\Settlement\Application\ReverseSettlementAllocationAction;
use App\Settlement\Enum\SettlementBucket;
use App\Settlement\Enum\SettlementDocumentKind;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class SettlementAllocationTest extends IntegrationTestCase
{
    private Company $company;
    private Counterparty $party;
    private MoneyAccount $account;
    private string $companyId;
    private string $actorId;

    protected function setUp(): void
    {
        parent::setUp();
        $user = UserBuilder::aUser()->build();
        $this->company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $this->party = CounterpartyBuilder::aCounterparty()->withCompany($this->company)->build();
        $this->account = MoneyAccountBuilder::aMoneyAccount()->forCompany($this->company)->build();
        foreach ([$user, $this->company, $this->party, $this->account] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $companyId = $this->company->getId();
        $actorId = $user->getId();
        self::assertNotNull($companyId);
        self::assertNotNull($actorId);
        $this->companyId = $companyId;
        $this->actorId = $actorId;
        (self::getContainer()->get(ConfigureSettlementBookAction::class))($companyId, $actorId, new \DateTimeImmutable('2026-11-01'));
    }

    private function obligation(string $amount, SettlementBucket $bucket = SettlementBucket::PAYABLE): string
    {
        $draft = (self::getContainer()->get(CreateSettlementDocumentAction::class))($this->companyId, $this->actorId, new CreateSettlementDocumentInput(Uuid::uuid7()->toString(), SettlementDocumentKind::OBLIGATION, $this->party->getId(), $bucket, Money::fromString($amount, 'RUB'), new \DateTimeImmutable('2026-11-01'), null, 'Акт'));
        $posted = (self::getContainer()->get(PostSettlementDocumentAction::class))($this->companyId, $this->actorId, $draft->documentId, $draft->version);
        self::assertNotNull($posted->itemId);

        return $posted->itemId;
    }

    private function payment(string $amount, bool $advance = false, CashDirection $direction = CashDirection::OUTFLOW): string
    {
        $cash = CashTransactionBuilder::aCashTransaction()->forCompany($this->company)->withMoneyAccount($this->account)->withAmount($amount)->withDirection($direction)->build();
        $cash->setOccurredAt(new \DateTimeImmutable('2026-11-01'))->setCounterparty($this->party);
        $this->em->persist($cash);
        $this->em->flush();
        $cashId = $cash->getId();
        self::assertNotNull($cashId);
        $posted = (self::getContainer()->get(RegisterCashSettlementAction::class))($this->companyId, $this->actorId, new RegisterCashSettlementInput(Uuid::uuid7()->toString(), $cashId, $advance, new \DateTimeImmutable('2026-11-01')));
        self::assertNotNull($posted->itemId);

        return $posted->itemId;
    }

    private function balance(string $bucket): string
    {
        return (string) $this->connection->fetchOne('SELECT COALESCE(SUM(delta_amount_minor),0) FROM settlement_movements WHERE company_id = ? AND bucket = ?', [$this->companyId, $bucket]);
    }

    public function testPartialPaymentAndReplayDoNotRepeatCashOrPnl(): void
    {
        $debt = $this->obligation('100000.00');
        $payment = $this->payment('40000.00');
        $input = new AllocateSettlementInput(Uuid::uuid7()->toString(), $debt, $payment, Money::fromString('40000.00', 'RUB'), new \DateTimeImmutable('2026-11-02'));
        $action = self::getContainer()->get(AllocateSettlementAction::class);
        $first = $action($this->companyId, $this->actorId, $input);
        self::assertSame($first, $action($this->companyId, $this->actorId, $input));
        self::assertSame('6000000', $this->balance('payable'));
        self::assertSame('0', $this->balance('unallocated_out'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cash_transaction WHERE company_id = ?', [$this->companyId]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM documents WHERE company_id = ?', [$this->companyId]));
    }

    public function testAdvanceAndSeveralPaymentsCloseObligation(): void
    {
        $advance = $this->payment('40000.00', true);
        $debt = $this->obligation('100000.00');
        $action = self::getContainer()->get(AllocateSettlementAction::class);
        $action($this->companyId, $this->actorId, new AllocateSettlementInput(Uuid::uuid7()->toString(), $debt, $advance, Money::fromString('40000.00', 'RUB'), new \DateTimeImmutable('2026-11-02')));
        $remaining = $this->payment('60000.00');
        $action($this->companyId, $this->actorId, new AllocateSettlementInput(Uuid::uuid7()->toString(), $debt, $remaining, Money::fromString('60000.00', 'RUB'), new \DateTimeImmutable('2026-11-02')));
        self::assertSame('0', $this->balance('payable'));
        self::assertSame('0', $this->balance('advance_given'));
    }

    public function testClassificationMovesOnlyUnallocatedRemainder(): void
    {
        $debt = $this->obligation('100000.00');
        $payment = $this->payment('40000.00');
        (self::getContainer()->get(AllocateSettlementAction::class))($this->companyId, $this->actorId, new AllocateSettlementInput(Uuid::uuid7()->toString(), $debt, $payment, Money::fromString('30000.00', 'RUB'), new \DateTimeImmutable('2026-11-02')));
        (self::getContainer()->get(ClassifySettlementPaymentAction::class))($this->companyId, $this->actorId, $payment, true, new ReverseSettlementInput(Uuid::uuid7()->toString(), new \DateTimeImmutable('2026-11-03'), 'Подтверждён аванс'));
        self::assertSame('7000000', $this->balance('payable'));
        self::assertSame('0', $this->balance('unallocated_out'));
        self::assertSame('1000000', $this->balance('advance_given'));
        $reconcile = self::getContainer()->get(\App\Settlement\Application\ReconcileSettlementRegisterAction::class);
        self::assertSame([], $reconcile($this->companyId)->differences);
        $this->connection->executeStatement("UPDATE settlement_movements SET delta_amount_minor=1 WHERE company_id=? AND bucket='advance_given'", [$this->companyId]);
        $reconcile($this->companyId, true);
        self::assertSame('1000000', $this->balance('advance_given'));
        self::assertSame([], $reconcile($this->companyId)->differences);
    }

    public function testDatedReversalRestoresBothSidesExactlyOnce(): void
    {
        $debt = $this->obligation('100000.00');
        $payment = $this->payment('40000.00');
        $id = (self::getContainer()->get(AllocateSettlementAction::class))($this->companyId, $this->actorId, new AllocateSettlementInput(Uuid::uuid7()->toString(), $debt, $payment, Money::fromString('40000.00', 'RUB'), new \DateTimeImmutable('2026-11-02')));
        $reverse = self::getContainer()->get(ReverseSettlementAllocationAction::class);
        $first = $reverse($this->companyId, $this->actorId, $id, new ReverseSettlementInput(Uuid::uuid7()->toString(), new \DateTimeImmutable('2026-11-04'), 'Отмена'));
        $again = $reverse($this->companyId, $this->actorId, $id, new ReverseSettlementInput(Uuid::uuid7()->toString(), new \DateTimeImmutable('2026-11-05'), 'Повтор'));
        self::assertSame($first->documentId, $again->documentId);
        self::assertSame('10000000', $this->balance('payable'));
        self::assertSame('4000000', $this->balance('unallocated_out'));
        $facade = self::getContainer()->get(\App\Settlement\Facade\SettlementFacadeInterface::class);
        foreach (['2026-11-01' => ['100000.00', '40000.00'], '2026-11-02' => ['60000.00', '0.00'], '2026-11-04' => ['100000.00', '40000.00']] as $date => [$debtBalance, $paymentBalance]) {
            $report = $facade->report($this->companyId, new \App\Settlement\Application\DTO\SettlementReportFilter(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable($date), 'RUB'));
            self::assertSame($debtBalance, $report->totals->closing->payable->toDecimalString());
            self::assertSame($paymentBalance, $report->totals->closing->unallocatedOut->toDecimalString());
        }
        self::assertSame([], (self::getContainer()->get(\App\Settlement\Application\ReconcileSettlementRegisterAction::class))($this->companyId)->differences);
    }

    public function testIncomingPaymentCanCloseSeveralReceivablesWithoutNettingPayables(): void
    {
        $first = $this->obligation('0.10', SettlementBucket::RECEIVABLE);
        $second = $this->obligation('0.20', SettlementBucket::RECEIVABLE);
        $this->obligation('50.00');
        $payment = $this->payment('0.30', false, CashDirection::INFLOW);
        $action = self::getContainer()->get(AllocateSettlementAction::class);
        foreach ([[$first, '0.10'], [$second, '0.20']] as [$debt, $amount]) {
            $action($this->companyId, $this->actorId, new AllocateSettlementInput(Uuid::uuid7()->toString(), $debt, $payment, Money::fromString($amount, 'RUB'), new \DateTimeImmutable('2026-11-02')));
        }
        self::assertSame('0', $this->balance('receivable'));
        self::assertSame('0', $this->balance('unallocated_in'));
        self::assertSame('5000', $this->balance('payable'));
    }

    public function testBackdatedAllocationCannotSpendMoneyNeededByLaterAllocation(): void
    {
        $debt = $this->obligation('100.00');
        $payment = $this->payment('40.00');
        $action = self::getContainer()->get(AllocateSettlementAction::class);
        $action($this->companyId, $this->actorId, new AllocateSettlementInput(Uuid::uuid7()->toString(), $debt, $payment, Money::fromString('30.00', 'RUB'), new \DateTimeImmutable('2026-11-04')));
        try {
            $action($this->companyId, $this->actorId, new AllocateSettlementInput(Uuid::uuid7()->toString(), $debt, $payment, Money::fromString('20.00', 'RUB'), new \DateTimeImmutable('2026-11-02')));
            self::fail('Later balance would become negative.');
        } catch (\DomainException) {
            self::assertSame('1000', $this->balance('unallocated_out'));
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM settlement_allocations WHERE company_id = ?', [$this->companyId]));
        }
    }

    public function testBackdatedClassificationCannotConsumeLaterAllocationBucket(): void
    {
        $debt = $this->obligation('100.00');
        $payment = $this->payment('40.00');
        (self::getContainer()->get(AllocateSettlementAction::class))($this->companyId, $this->actorId, new AllocateSettlementInput(Uuid::uuid7()->toString(), $debt, $payment, Money::fromString('30.00', 'RUB'), new \DateTimeImmutable('2026-11-04')));
        try {
            (self::getContainer()->get(ClassifySettlementPaymentAction::class))($this->companyId, $this->actorId, $payment, true, new ReverseSettlementInput(Uuid::uuid7()->toString(), new \DateTimeImmutable('2026-11-02'), 'Аванс'));
            self::fail('Classification would invalidate the later unallocated debit.');
        } catch (\DomainException) {
            self::assertSame('1000', $this->balance('unallocated_out'));
            self::assertSame('0', $this->balance('advance_given'));
        }
    }

    public function testClassificationCannotBeReversedWhileItsAdvanceIsConsumed(): void
    {
        $debt = $this->obligation('100.00');
        $payment = $this->payment('40.00');
        $classification = (self::getContainer()->get(ClassifySettlementPaymentAction::class))($this->companyId, $this->actorId, $payment, true, new ReverseSettlementInput(Uuid::uuid7()->toString(), new \DateTimeImmutable('2026-11-02'), 'Аванс'));
        (self::getContainer()->get(AllocateSettlementAction::class))($this->companyId, $this->actorId, new AllocateSettlementInput(Uuid::uuid7()->toString(), $debt, $payment, Money::fromString('30.00', 'RUB'), new \DateTimeImmutable('2026-11-03')));
        try {
            (self::getContainer()->get(\App\Settlement\Application\ReverseSettlementDocumentAction::class))($this->companyId, $this->actorId, $classification->documentId, new ReverseSettlementInput(Uuid::uuid7()->toString(), new \DateTimeImmutable('2026-11-04'), 'Отмена аванса'));
            self::fail('Consumed classification cannot be reversed.');
        } catch (\DomainException) {
            self::assertSame('1000', $this->balance('advance_given'));
            self::assertSame('0', $this->balance('unallocated_out'));
        }
    }

    public function testAllocationRequestCannotBeReusedWithAnotherAmount(): void
    {
        $debt = $this->obligation('100.00');
        $payment = $this->payment('40.00');
        $request = Uuid::uuid7()->toString();
        $action = self::getContainer()->get(AllocateSettlementAction::class);
        $action($this->companyId, $this->actorId, new AllocateSettlementInput($request, $debt, $payment, Money::fromString('10.00', 'RUB'), new \DateTimeImmutable('2026-11-02')));
        $this->expectException(\DomainException::class);
        $action($this->companyId, $this->actorId, new AllocateSettlementInput($request, $debt, $payment, Money::fromString('20.00', 'RUB'), new \DateTimeImmutable('2026-11-02')));
    }

    /** @return iterable<string,array{string}> */
    public static function invalidPrimaryFacts(): iterable
    {
        yield 'economic direction' => ['direction'];
        yield 'reversal before original' => ['reversal'];
        yield 'due date diverges from document' => ['dueDate'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPrimaryFacts')]
    public function testRebuildRefusesAnEconomicallyInvalidPrimaryAllocation(string $case): void
    {
        $debt = $this->obligation('100.00');
        $wrongSide = $this->obligation('100.00', SettlementBucket::RECEIVABLE);
        $payment = $this->payment('40.00');
        $allocation = (self::getContainer()->get(AllocateSettlementAction::class))($this->companyId, $this->actorId, new AllocateSettlementInput(Uuid::uuid7()->toString(), $debt, $payment, Money::fromString('30.00', 'RUB'), new \DateTimeImmutable('2026-11-02')));
        $reconcile = self::getContainer()->get(\App\Settlement\Application\ReconcileSettlementRegisterAction::class);
        self::assertSame([], $reconcile($this->companyId)->differences);
        if ('reversal' === $case) {
            $reverse = (self::getContainer()->get(ReverseSettlementAllocationAction::class))($this->companyId, $this->actorId, $allocation, new ReverseSettlementInput(Uuid::uuid7()->toString(), new \DateTimeImmutable('2026-11-04'), 'Отмена'));
            $this->connection->executeStatement("UPDATE settlement_documents SET effective_date='2026-11-01' WHERE company_id=? AND id=?", [$this->companyId, $reverse->documentId]);
        } elseif ('dueDate' === $case) {
            $this->connection->executeStatement("UPDATE settlement_items SET due_date='2026-11-03' WHERE company_id=? AND id=?", [$this->companyId, $debt]);
        } else {
            $this->connection->executeStatement('UPDATE settlement_allocations SET obligation_item_id=? WHERE company_id=? AND id=?', [$wrongSide, $this->companyId, $allocation]);
        }
        $debtBefore = $this->balance('payable');
        $receivableBefore = $this->balance('receivable');
        $this->em->clear();
        try {
            (self::getContainer()->get(\App\Settlement\Application\ReconcileSettlementRegisterAction::class))($this->companyId, true);
            self::fail('Invalid primary allocation must not be repaired into the journal.');
        } catch (\DomainException $exception) {
            self::assertSame(match ($case) {
                'direction' => 'Недопустимое распределение оплаты.', 'reversal' => 'Первичная отмена не согласована с исходным событием.', default => 'Первичный документ и позиция не согласованы.'
            }, $exception->getMessage());
            self::assertSame($debtBefore, $this->balance('payable'));
            self::assertSame($receivableBefore, $this->balance('receivable'));
        }
    }

    public function testRebuildRefusesSourceReversalBeforeDependentReversal(): void
    {
        $debt = $this->obligation('100.00');
        $originalId = (string) $this->connection->fetchOne('SELECT document_id FROM settlement_items WHERE company_id=? AND id=?', [$this->companyId, $debt]);
        $correction = (self::getContainer()->get(\App\Settlement\Application\CorrectSettlementItemAction::class))($this->companyId, $this->actorId, new \App\Settlement\Application\DTO\CorrectSettlementItemInput(Uuid::uuid7()->toString(), $debt, Money::fromString('50.00', 'RUB'), true, new \DateTimeImmutable('2026-11-02'), 'Уточнение'));
        $reverse = self::getContainer()->get(\App\Settlement\Application\ReverseSettlementDocumentAction::class);
        $reverse($this->companyId, $this->actorId, $correction->documentId, new ReverseSettlementInput(Uuid::uuid7()->toString(), new \DateTimeImmutable('2026-11-05'), 'Отмена уточнения'));
        $baseReversal = $reverse($this->companyId, $this->actorId, $originalId, new ReverseSettlementInput(Uuid::uuid7()->toString(), new \DateTimeImmutable('2026-11-06'), 'Отмена основания'));
        self::assertSame([], (self::getContainer()->get(\App\Settlement\Application\ReconcileSettlementRegisterAction::class))($this->companyId)->differences);
        $this->connection->executeStatement("UPDATE settlement_documents SET effective_date='2026-11-03' WHERE company_id=? AND id=?", [$this->companyId, $baseReversal->documentId]);
        $before = $this->connection->fetchAllAssociative('SELECT id,effective_date,delta_amount_minor FROM settlement_movements WHERE company_id=? ORDER BY id', [$this->companyId]);
        $this->em->clear();
        try {
            (self::getContainer()->get(\App\Settlement\Application\ReconcileSettlementRegisterAction::class))($this->companyId, true);
            self::fail('Base reversal cannot precede its dependency reversal.');
        } catch (\DomainException $exception) {
            self::assertSame('Отмена основания предшествует отмене зависимых событий.', $exception->getMessage());
            self::assertSame($before, $this->connection->fetchAllAssociative('SELECT id,effective_date,delta_amount_minor FROM settlement_movements WHERE company_id=? ORDER BY id', [$this->companyId]));
        }
    }
}

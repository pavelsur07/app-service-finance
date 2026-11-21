<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Facade;

use App\Cash\Application\DTO\PaymentIntake\CreateMcpCashDraft;
use App\Cash\Application\PaymentIntake\PaymentIntakeAction;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\PaymentPlan\PaymentPlanType;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Exception\McpCashDraftException;
use App\Cash\Facade\CashFacade;
use App\Company\Entity\Company;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;

final class McpCashDraftTest extends IntegrationTestCase
{
    public function testReplayAndConfirmationPreserveProvenanceAndCashEffect(): void
    {
        [$company, $actorId, $accountId] = $this->fixture();
        $companyId = (string) $company->getId();
        $connectionId = Uuid::uuid7()->toString();
        $cash = self::getContainer()->get(CashFacade::class);
        $intake = self::getContainer()->get(PaymentIntakeAction::class);
        $balanceBefore = $this->connection->fetchOne('SELECT current_balance FROM money_account WHERE id = ?', [$accountId]);
        $draft = $cash->createMcpCashDraft($this->input($companyId, $actorId, $connectionId, $accountId));
        self::assertSame('awaiting_confirmation', $draft->status);
        self::assertSame($draft->draftId, $cash->createMcpCashDraft($this->input($companyId, $actorId, $connectionId, $accountId, currency: 'RUB'))->draftId);
        self::assertSame(0, $this->countFacts($companyId));
        self::assertSame($balanceBefore, $this->connection->fetchOne('SELECT current_balance FROM money_account WHERE id = ?', [$accountId]));
        self::assertSame([], $cash->openPaymentIntakeDraftIds($companyId, $actorId));
        self::assertSame([$draft->draftId], array_column($intake->listMcpDrafts($companyId, $actorId, null, false), 'id'));
        $stored = $intake->getMcpDraft($companyId, $actorId, $draft->draftId);
        self::assertSame('payment-42', $stored->mcpExternalId);
        self::assertFalse($stored->items[0]['paid_confirmed']);

        try {
            $intake->confirmMcp($companyId, $actorId, $draft->draftId, $stored->revision, false);
            self::fail('Confirmation requires an explicit acknowledgement.');
        } catch (\DomainException $exception) {
            self::assertSame('Подтвердите факт оплаты.', $exception->getMessage());
        }
        self::assertSame(0, $this->countFacts($companyId));
        $result = $intake->confirmMcp($companyId, $actorId, $draft->draftId, $stored->revision, true);
        self::assertSame($result->transactionIds, $intake->confirmMcp($companyId, $actorId, $draft->draftId, $stored->revision, true)->transactionIds);
        self::assertSame(1, $this->countFacts($companyId));
        self::assertSame('3750.00', $this->connection->fetchOne('SELECT current_balance FROM money_account WHERE id = ?', [$accountId]));
        self::assertSame([
            'amount' => '1250.00',
            'import_source' => 'mcp',
            'external_id' => $draft->draftId.'/'.$stored->items[0]['id'],
        ], $this->connection->fetchAssociative('SELECT amount, import_source, external_id FROM cash_transaction WHERE id = ?', [$result->transactionIds[0]]));
        self::assertSame('confirmed', $cash->createMcpCashDraft($this->input($companyId, $actorId, $connectionId, $accountId))->status);
        self::assertNotNull($intake->getMcpDraft($companyId, $actorId, $draft->draftId)->confirmedAt);
    }

    public function testConflictingReplayLeavesOriginalDraftAndNoFact(): void
    {
        [$company, $actorId, $accountId] = $this->fixture();
        $companyId = (string) $company->getId();
        $connectionId = Uuid::uuid7()->toString();
        $cash = self::getContainer()->get(CashFacade::class);
        $draft = $cash->createMcpCashDraft($this->input($companyId, $actorId, $connectionId, $accountId));
        self::assertNotSame($draft->draftId, $cash->createMcpCashDraft($this->input($companyId, $actorId, Uuid::uuid7()->toString(), $accountId))->draftId);
        try {
            $cash->createMcpCashDraft($this->input($companyId, $actorId, $connectionId, $accountId, amount: '1251.00'));
            self::fail('Conflicting replay must fail.');
        } catch (McpCashDraftException $exception) {
            self::assertSame('idempotency_conflict', $exception->reason);
        }
        self::assertSame(0, $this->countFacts($companyId));
        self::assertSame('1250.00', $this->connection->fetchOne("SELECT items->0->>'amount' FROM cash_payment_intake_draft WHERE id = ?", [$draft->draftId]));
    }

    public function testClosedPeriodPreventsNewDraftAndCancelledReplayIsInert(): void
    {
        [$company, $actorId, $accountId] = $this->fixture();
        $companyId = (string) $company->getId();
        $cash = self::getContainer()->get(CashFacade::class);
        $intake = self::getContainer()->get(PaymentIntakeAction::class);
        $input = $this->input($companyId, $actorId, Uuid::uuid7()->toString(), $accountId);
        $draft = $cash->createMcpCashDraft($input);
        $intake->cancelMcp($companyId, $actorId, $draft->draftId, 1);
        $company->setFinanceLockBefore(new \DateTimeImmutable('2026-11-01'));
        $this->em->flush();

        self::assertSame('cancelled', $cash->createMcpCashDraft($input)->status);
        self::assertSame(0, $this->countFacts($companyId));
        try {
            $cash->createMcpCashDraft($this->input($companyId, $actorId, Uuid::uuid7()->toString(), $accountId));
            self::fail('Closed period must reject a new draft.');
        } catch (McpCashDraftException $exception) {
            self::assertSame('period_closed', $exception->reason);
        }
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM cash_payment_intake_draft WHERE company_id = ?', [$companyId]));
    }

    public function testExternalIdentityIsScopedAndEnforcedByTheDatabase(): void
    {
        [$company, $actorId, $accountId] = $this->fixture();
        [$other, $otherActor, $otherAccount] = $this->fixture(2);
        $companyId = (string) $company->getId();
        $otherId = (string) $other->getId();
        $connectionId = Uuid::uuid7()->toString();
        $cash = self::getContainer()->get(CashFacade::class);
        $first = $cash->createMcpCashDraft($this->input($companyId, $actorId, $connectionId, $accountId));
        $second = $cash->createMcpCashDraft($this->input($otherId, $otherActor, $connectionId, $otherAccount));
        self::assertNotSame($first->draftId, $second->draftId);
        $intake = self::getContainer()->get(PaymentIntakeAction::class);
        try {
            $intake->getMcpDraft($otherId, $otherActor, $first->draftId);
            self::fail('The draft belongs to another company.');
        } catch (\DomainException $exception) {
            self::assertSame('Черновик не найден.', $exception->getMessage());
        }

        $third = $cash->createMcpCashDraft($this->input($companyId, $actorId, Uuid::uuid7()->toString(), $accountId));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->transactional(fn () => $this->connection->executeStatement('UPDATE cash_payment_intake_draft SET mcp_connection_id = ? WHERE id = ?', [$connectionId, $third->draftId]));
    }

    public function testForeignAccountIsUnavailable(): void
    {
        [$company, $actorId] = $this->fixture();
        [, , $otherAccount] = $this->fixture(2);
        $companyId = (string) $company->getId();
        try {
            self::getContainer()->get(CashFacade::class)->createMcpCashDraft($this->input($companyId, $actorId, Uuid::uuid7()->toString(), $otherAccount));
            self::fail('Company must not submit a draft for an account it cannot access.');
        } catch (McpCashDraftException $exception) {
            self::assertSame('reference_unavailable', $exception->reason);
        }
        self::assertSame(0, $this->countFacts($companyId));
    }

    public function testLeafCategoryInheritsPaymentDirection(): void
    {
        [$company, $actorId, $accountId] = $this->fixture();
        $companyId = (string) $company->getId();
        $parent = new CashflowCategory(Uuid::uuid7()->toString(), $company);
        $parent->setName('Приход')->setOperationType(PaymentPlanType::INFLOW);
        $leaf = new CashflowCategory(Uuid::uuid7()->toString(), $company);
        $leaf->setName('Продажи')->setParent($parent);
        $this->em->persist($parent);
        $this->em->persist($leaf);
        $this->em->flush();
        $cash = self::getContainer()->get(CashFacade::class);
        $connectionId = Uuid::uuid7()->toString();
        $date = new \DateTimeImmutable('2026-10-10');
        $categoryId = (string) $leaf->getId();
        $cash->createMcpCashDraft(new CreateMcpCashDraft(
            $companyId, $actorId, $connectionId, 'accepted', $accountId, $date, CashDirection::INFLOW, '100.00', categoryId: $categoryId,
        ));
        $this->expectException(McpCashDraftException::class);
        $cash->createMcpCashDraft(new CreateMcpCashDraft(
            $companyId, $actorId, $connectionId, 'rejected', $accountId, $date, CashDirection::OUTFLOW, '100.00', categoryId: $categoryId,
        ));
    }

    #[DataProvider('confirmationRevocations')]
    public function testConfirmationRechecksChangedConditions(string $condition, string $message): void
    {
        [$company, $actorId, $accountId] = $this->fixture();
        $companyId = (string) $company->getId();
        $draft = self::getContainer()->get(CashFacade::class)->createMcpCashDraft(
            $this->input($companyId, $actorId, Uuid::uuid7()->toString(), $accountId),
        );
        $balance = $this->connection->fetchOne('SELECT current_balance FROM money_account WHERE id = ?', [$accountId]);
        if ('period' === $condition) {
            $company->setFinanceLockBefore(new \DateTimeImmutable('2026-11-01'));
        } elseif ('ownership' === $condition) {
            $newOwner = UserBuilder::aUser()->withIndex(2)->build();
            $this->em->persist($newOwner);
            $company->setUser($newOwner);
        } else {
            $this->connection->executeStatement('UPDATE money_account SET is_active = false WHERE id = ?', [$accountId]);
        }
        $this->em->flush();

        try {
            self::getContainer()->get(PaymentIntakeAction::class)->confirmMcp($companyId, $actorId, $draft->draftId, 1, true);
            self::fail('The condition changed after creation.');
        } catch (\DomainException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
        self::assertSame(0, $this->countFacts($companyId));
        self::assertSame($balance, $this->connection->fetchOne('SELECT current_balance FROM money_account WHERE id = ?', [$accountId]));
    }

    /** @return iterable<string, array{string, string}> */
    public static function confirmationRevocations(): iterable
    {
        yield 'period closed' => ['period', 'Период платежа закрыт.'];
        yield 'ownership revoked' => ['ownership', 'Нет права записи платежей компании.'];
        yield 'account archived' => ['account', 'Выбранный счёт недоступен.'];
    }

    private function countFacts(string $companyId): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM cash_transaction WHERE company_id = ?', [$companyId]);
    }

    /** @return array{Company, string, string} */
    private function fixture(int $index = 1): array
    {
        $actor = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($actor)->build();
        $account = new MoneyAccount(Uuid::uuid7()->toString(), $company, MoneyAccountType::BANK, 'Основной', 'RUB');
        $account->setOpeningBalance('5000.00');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2026-01-01'));
        $companyId = (string) $company->getId();
        $project = new ProjectDirection(Uuid::uuid7()->toString(), $company, 'Общий', ProjectDirection::CODE_GENERAL);
        $center = new FinancialResponsibilityCenter($companyId, FinancialResponsibilityCenter::CODE_GENERAL, FinancialResponsibilityCenter::NAME_GENERAL);
        $pair = new FinancialResponsibilityCenterProject($companyId, $project, $center);
        foreach ([$actor, $company, $account, $project, $center, $pair] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        return [$company, (string) $actor->getId(), (string) $account->getId()];
    }

    private function input(string $companyId, string $actorId, string $connectionId, string $accountId, string $amount = '1250.00', ?string $currency = null): CreateMcpCashDraft
    {
        return new CreateMcpCashDraft($companyId, $actorId, $connectionId, 'payment-42', $accountId, new \DateTimeImmutable('2026-10-10'), CashDirection::OUTFLOW, $amount, $currency);
    }
}

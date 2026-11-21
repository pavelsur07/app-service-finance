<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Facade;

use App\Cash\Application\DTO\PaymentIntake\CreateMcpCashDraft;
use App\Cash\Application\PaymentIntake\PaymentIntakeAction;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Enum\Accounts\MoneyAccountType;
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
        self::assertSame([$draft->draftId], $intake->openMcpIds($companyId, $actorId));
        $stored = $intake->getMcpDraft($companyId, $actorId, $draft->draftId);
        self::assertSame('payment-42', $stored->mcpExternalId);
        self::assertFalse($stored->items[0]['paid_confirmed']);

        $result = $intake->confirmMcp($companyId, $actorId, $draft->draftId, $stored->revision);
        self::assertSame($result->transactionIds, $intake->confirmMcp($companyId, $actorId, $draft->draftId, $stored->revision)->transactionIds);
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

    private function countFacts(string $companyId): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM cash_transaction WHERE company_id = ?', [$companyId]);
    }

    /** @return array{Company, string, string} */
    private function fixture(): array
    {
        $actor = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($actor)->build();
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

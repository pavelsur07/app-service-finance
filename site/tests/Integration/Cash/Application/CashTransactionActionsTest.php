<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Analytics\Infrastructure\Cache\SnapshotCacheInvalidator;
use App\Cash\Application\AddCashTransactionAction;
use App\Cash\Application\CreatePnlDocumentFromTransactionAction;
use App\Cash\Application\DeleteCashTransactionAction;
use App\Cash\Application\RestoreCashTransactionAction;
use App\Cash\DTO\CashTransactionDTO;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\PaymentPlan\PaymentPlan;
use App\Cash\Entity\PaymentPlan\PaymentPlanMatch;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\PaymentPlan\PaymentPlanStatus;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Exception\FinancePeriodLockedException;
use App\Company\Entity\Company;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Finance\Entity\Document;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Action'ы записи транзакции ДДС (этап 5.3 плана Cash; было CashTransactionService).
 * flush() перенесён из сервиса и матчера плановых платежей в Action'ы — тест
 * проверяет, что всё по-прежнему доходит до БД.
 */
final class CashTransactionActionsTest extends IntegrationTestCase
{
    private Company $company;
    private MoneyAccount $account;
    private CashflowCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $user = UserBuilder::aUser()->withEmail('cash-actions@example.com')->build();
        $this->company = CompanyBuilder::aCompany()->withOwner($user)->withName('Actions')->build();
        $this->account = new MoneyAccount(Uuid::uuid4()->toString(), $this->company, MoneyAccountType::BANK, 'Main', 'RUB');
        $this->account->setOpeningBalance('0');
        $this->account->setOpeningBalanceDate(new \DateTimeImmutable('2024-01-01'));
        $this->category = new CashflowCategory(Uuid::uuid4()->toString(), $this->company);
        $this->category->setName('Аренда');
        $this->category->setAllowPlDocument(true);
        $plCategory = PLCategoryBuilder::aPLCategory()->withId(Uuid::uuid4()->toString())->forCompany($this->company)->withName('Аренда')->build();
        $this->category->setPlCategory($plCategory);

        $project = new ProjectDirection(Uuid::uuid4()->toString(), $this->company, 'Общий', ProjectDirection::CODE_GENERAL);
        $center = new FinancialResponsibilityCenter((string) $this->company->getId(), FinancialResponsibilityCenter::CODE_GENERAL, FinancialResponsibilityCenter::NAME_GENERAL);

        foreach ([$user, $this->company, $this->account, $plCategory, $this->category, $project, $center] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->persist(new FinancialResponsibilityCenterProject((string) $this->company->getId(), $project, $center));
        $this->em->flush();
    }

    public function testAddPersistsPaymentPlanMatch(): void
    {
        $plan = new PaymentPlan(Uuid::uuid4()->toString(), $this->company, $this->category, new \DateTimeImmutable('2024-02-10'), '500.00');
        $plan->setStatus(PaymentPlanStatus::APPROVED);
        $this->em->persist($plan);
        $this->em->flush();

        $tx = $this->action(AddCashTransactionAction::class)($this->dto('500.00', '2024-02-10'));
        $txId = (string) $tx->getId();
        $planId = (string) $plan->getId();
        $this->em->clear();

        $match = $this->em->getRepository(PaymentPlanMatch::class)->findOneBy(['transaction' => $txId]);
        self::assertInstanceOf(PaymentPlanMatch::class, $match);
        self::assertSame($planId, $match->getPlan()->getId());
        $planFromDb = $this->em->find(PaymentPlan::class, $planId);
        self::assertSame(PaymentPlanStatus::PAID, $planFromDb?->getStatus());
    }

    public function testDeleteAndRestoreReachDatabase(): void
    {
        $cacheInvalidator = self::getContainer()->get(SnapshotCacheInvalidator::class);
        $versionBefore = $cacheInvalidator->resolveVersionForCompany($this->company);

        $tx = $this->action(AddCashTransactionAction::class)($this->dto('100.00', '2024-02-10'));
        $txId = (string) $tx->getId();
        // CashTransactionsChanged доходит до подписчика Analytics: версия кэша дашборда растёт.
        self::assertSame($versionBefore + 1, $cacheInvalidator->resolveVersionForCompany($this->company));

        $this->action(DeleteCashTransactionAction::class)($tx, null, 'test');
        self::assertSame($versionBefore + 2, $cacheInvalidator->resolveVersionForCompany($this->company));
        $this->em->clear();
        $deleted = $this->em->find(CashTransaction::class, $txId);
        self::assertInstanceOf(CashTransaction::class, $deleted);
        self::assertTrue($deleted->isDeleted());

        $this->action(RestoreCashTransactionAction::class)($deleted);
        $this->em->clear();
        self::assertFalse($this->em->find(CashTransaction::class, $txId)?->isDeleted());
    }

    public function testDeleteInLockedPeriodWritesNothing(): void
    {
        $tx = $this->action(AddCashTransactionAction::class)($this->dto('100.00', '2024-02-10'));
        $this->company->setFinanceLockBefore(new \DateTimeImmutable('2024-03-01'));
        $this->em->flush();

        try {
            $this->action(DeleteCashTransactionAction::class)($tx);
            self::fail('FinancePeriodLockedException expected.');
        } catch (FinancePeriodLockedException) {
        }

        $this->em->clear();
        self::assertFalse($this->em->find(CashTransaction::class, (string) $tx->getId())?->isDeleted());
    }

    public function testPnlDocumentForFullyAllocatedTransactionIsRejected(): void
    {
        $tx = $this->action(AddCashTransactionAction::class)($this->dto('100.00', '2024-02-10'));
        $document = $this->action(CreatePnlDocumentFromTransactionAction::class)($tx);
        $documentId = (string) $document->getId();
        $this->em->clear();
        self::assertInstanceOf(Document::class, $this->em->find(Document::class, $documentId));

        $tx = $this->em->find(CashTransaction::class, (string) $tx->getId());
        self::assertInstanceOf(CashTransaction::class, $tx);

        $this->expectException(\DomainException::class);

        $this->action(CreatePnlDocumentFromTransactionAction::class)($tx);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function action(string $class): object
    {
        $action = self::getContainer()->get($class);
        self::assertInstanceOf($class, $action);

        return $action;
    }

    private function dto(string $amount, string $date): CashTransactionDTO
    {
        $dto = new CashTransactionDTO();
        $dto->companyId = (string) $this->company->getId();
        $dto->moneyAccountId = (string) $this->account->getId();
        $dto->direction = CashDirection::OUTFLOW;
        $dto->amount = $amount;
        $dto->currency = 'RUB';
        $dto->occurredAt = new \DateTimeImmutable($date);
        $dto->cashflowCategoryId = $this->category->getId();

        return $dto;
    }
}

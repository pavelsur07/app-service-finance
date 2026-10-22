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
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Company\Entity\Company;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Finance\Entity\Document;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
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

    /**
     * Две одновременные отправки формы: обе не нашли транзакцию по ключу, вторая упирается в PK
     * и возвращает транзакцию первой, а не 500.
     */
    public function testConcurrentSubmissionWithSameKeyReturnsFirstTransaction(): void
    {
        $key = Uuid::uuid7()->toString();
        $first = $this->action(AddCashTransactionAction::class)($this->dto('100.00', '2024-02-10', $key));
        self::assertSame($key, $first->getId());
        $this->em->clear();

        $registry = self::getContainer()->get(ManagerRegistry::class);
        // Поиск по ключу «не успел» увидеть транзакцию первой отправки.
        $missingLookup = new class($registry) extends CashTransactionRepository {
            public function findOneByIdAndCompanyId(string $id, string $companyId): ?CashTransaction
            {
                return null;
            }
        };
        $container = self::getContainer();
        $action = new AddCashTransactionAction(
            $this->em,
            $container->get(\App\Cash\Service\Transaction\CashTransactionService::class),
            $container->get(\App\Cash\Application\Service\DailyBalanceRecalculator::class),
            $missingLookup,
            $container->get(\App\Cash\Service\PaymentPlan\PaymentPlanMatcher::class),
            $container->get('event_dispatcher'),
            $container->get(\App\Cash\Application\Service\CashTransactionResponsibilityCenterResolver::class),
            $container->get(\App\Cash\Service\Transaction\CashTransactionSplitSynchronizer::class),
            $registry,
        );

        $second = $action($this->dto('100.00', '2024-02-10', $key));

        self::assertSame($key, $second->getId());
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM cash_transaction WHERE company_id = :company',
            ['company' => $this->company->getId()],
        ));
    }

    public function testKeyOfAnotherCompanyIsNotTreatedAsRepeat(): void
    {
        $key = Uuid::uuid7()->toString();
        $this->action(AddCashTransactionAction::class)($this->dto('100.00', '2024-02-10', $key));
        $this->em->clear();

        $user = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('cash-actions-other@example.com')->build();
        $other = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->withOwner($user)->withName('Other')->build();
        $account = new MoneyAccount(Uuid::uuid4()->toString(), $other, MoneyAccountType::BANK, 'Other', 'RUB');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2024-01-01'));
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $other, 'Общий', ProjectDirection::CODE_GENERAL);
        $center = new FinancialResponsibilityCenter((string) $other->getId(), FinancialResponsibilityCenter::CODE_GENERAL, FinancialResponsibilityCenter::NAME_GENERAL);
        foreach ([$user, $other, $account, $project, $center] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->persist(new FinancialResponsibilityCenterProject((string) $other->getId(), $project, $center));
        $this->em->flush();

        $dto = $this->dto('100.00', '2024-02-10', $key);
        $dto->companyId = (string) $other->getId();
        $dto->moneyAccountId = (string) $account->getId();
        $dto->cashflowCategoryId = null;

        $this->expectException(UniqueConstraintViolationException::class);
        $this->action(AddCashTransactionAction::class)($dto);
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

    private function dto(string $amount, string $date, ?string $idempotencyKey = null): CashTransactionDTO
    {
        $dto = new CashTransactionDTO();
        $dto->idempotencyKey = $idempotencyKey;
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

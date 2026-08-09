<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Analytics\Infrastructure\Cache\SnapshotCacheInvalidator;
use App\Cash\Application\DTO\CreateCashTransferCommand;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transfer\CashTransfer;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Exception\OpeningBalanceDateInFutureException;
use App\Cash\Facade\CashFacade;
use App\Cash\Repository\Accounts\MoneyAccountDailyBalanceRepository;
use App\Cash\Repository\PaymentPlan\PaymentPlanMatchRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Repository\Transfer\CashTransferRepository;
use App\Cash\Service\Category\CashflowSystemCategoryService;
use App\Company\Entity\Company;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Shared\Entity\AuditLog;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class CreateCashTransferActionTest extends IntegrationTestCase
{
    private CashFacade $cashFacade;
    private CashTransferRepository $transferRepository;
    private CashTransactionRepository $transactionRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashFacade = self::getContainer()->get(CashFacade::class);
        $this->transferRepository = self::getContainer()->get(CashTransferRepository::class);
        $this->transactionRepository = self::getContainer()->get(CashTransactionRepository::class);
    }

    public function testCreatesCrossCurrencyTransferWithTechnicalLegs(): void
    {
        [$company, $sourceAccount, $targetAccount] = $this->fixture(
            'RUB',
            'USD',
            sourceType: MoneyAccountType::CASH,
            targetType: MoneyAccountType::EWALLET,
        );
        $date = new \DateTimeImmutable('2026-08-09');

        $result = $this->cashFacade->createTransfer(new CreateCashTransferCommand(
            companyId: (string) $company->getId(),
            sourceAccountId: (string) $sourceAccount->getId(),
            targetAccountId: (string) $targetAccount->getId(),
            sourceAmount: '9500.00',
            targetAmount: '100.00',
            occurredAt: $date,
            idempotencyKey: 'create-transfer-1',
            description: 'Покупка долларов',
        ));

        self::assertTrue($result->created);
        self::assertFalse($result->duplicate);
        $transfer = $this->transferRepository->find($result->transferId);
        $source = $this->transactionRepository->find($result->sourceTransactionId);
        $target = $this->transactionRepository->find($result->targetTransactionId);

        self::assertNotNull($transfer);
        self::assertNotNull($source);
        self::assertNotNull($target);
        self::assertSame(CashDirection::OUTFLOW, $source->getDirection());
        self::assertSame(CashDirection::INFLOW, $target->getDirection());
        self::assertSame('9500.00', $source->getAmount());
        self::assertSame('100.00', $target->getAmount());
        self::assertSame('RUB', $source->getCurrency());
        self::assertSame('USD', $target->getCurrency());
        self::assertSame(MoneyAccountType::CASH, $source->getMoneyAccount()->getType());
        self::assertSame(MoneyAccountType::EWALLET, $target->getMoneyAccount()->getType());
        self::assertTrue($source->isTransfer());
        self::assertTrue($target->isTransfer());
        self::assertSame('Покупка долларов', $source->getDescription());
        self::assertSame('Покупка долларов', $target->getDescription());
        self::assertCount(1, $source->getSplits());
        self::assertCount(1, $target->getSplits());
        self::assertSame(CashflowCategory::CODE_TECHNICAL_OUT, $source->getSingleSplitCategory()?->getSystemCode());
        self::assertSame(CashflowCategory::CODE_TECHNICAL_IN, $target->getSingleSplitCategory()?->getSystemCode());
        self::assertSame('0.010526315789473684', $transfer->getEffectiveRate());
        self::assertSame('RUB', $transfer->getRateBaseCurrency());
        self::assertSame('USD', $transfer->getRateQuoteCurrency());
    }

    public function testCreatesSameCurrencyTransferWithoutFxMetadata(): void
    {
        [$company, $sourceAccount, $targetAccount] = $this->fixture('USD', 'USD');

        $result = $this->cashFacade->createTransfer(new CreateCashTransferCommand(
            companyId: (string) $company->getId(),
            sourceAccountId: (string) $sourceAccount->getId(),
            targetAccountId: (string) $targetAccount->getId(),
            sourceAmount: '100.00',
            targetAmount: '100.00',
            occurredAt: new \DateTimeImmutable('2026-08-09'),
            idempotencyKey: 'create-transfer-same-currency',
        ));

        $transfer = $this->transferRepository->find($result->transferId);

        self::assertNotNull($transfer);
        self::assertNull($transfer->getEffectiveRate());
        self::assertNull($transfer->getRateBaseCurrency());
        self::assertNull($transfer->getRateQuoteCurrency());
        self::assertNull($transfer->getRateDate());
        self::assertNull($transfer->getRateSource());
    }

    public function testRejectsTargetAccountFromAnotherCompany(): void
    {
        [$company, $sourceAccount] = $this->fixture('RUB', 'USD');
        [, , $foreignAccount] = $this->fixture('RUB', 'USD');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Счёт назначения не найден.');

        $this->cashFacade->createTransfer(new CreateCashTransferCommand(
            companyId: (string) $company->getId(),
            sourceAccountId: (string) $sourceAccount->getId(),
            targetAccountId: (string) $foreignAccount->getId(),
            sourceAmount: '9500.00',
            targetAmount: '100.00',
            occurredAt: new \DateTimeImmutable('2026-08-09'),
            idempotencyKey: 'create-transfer-foreign',
        ));
    }

    public function testRejectsSameAccount(): void
    {
        [$company, $account] = $this->fixture('RUB', 'RUB');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Счета перевода должны различаться.');

        $this->cashFacade->createTransfer(new CreateCashTransferCommand(
            companyId: (string) $company->getId(),
            sourceAccountId: (string) $account->getId(),
            targetAccountId: (string) $account->getId(),
            sourceAmount: '100.00',
            targetAmount: '100.00',
            occurredAt: new \DateTimeImmutable('2026-08-09'),
            idempotencyKey: 'create-transfer-same-account',
        ));
    }

    public function testRejectsInactiveAccount(): void
    {
        [$company, $sourceAccount, $targetAccount] = $this->fixture('RUB', 'USD');
        $targetAccount->setIsActive(false);
        $this->em->flush();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Переводы разрешены только между активными счетами.');

        $this->cashFacade->createTransfer(new CreateCashTransferCommand(
            companyId: (string) $company->getId(),
            sourceAccountId: (string) $sourceAccount->getId(),
            targetAccountId: (string) $targetAccount->getId(),
            sourceAmount: '9500.00',
            targetAmount: '100.00',
            occurredAt: new \DateTimeImmutable('2026-08-09'),
            idempotencyKey: 'create-transfer-inactive',
        ));
    }

    public function testRejectsDateBeforeAccountOpening(): void
    {
        [$company, $sourceAccount, $targetAccount] = $this->fixture('RUB', 'USD');
        $targetAccount->setOpeningBalanceDate(new \DateTimeImmutable('2026-08-10'));
        $this->em->flush();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Дата перевода не может быть раньше даты открытия счёта.');

        $this->cashFacade->createTransfer(new CreateCashTransferCommand(
            companyId: (string) $company->getId(),
            sourceAccountId: (string) $sourceAccount->getId(),
            targetAccountId: (string) $targetAccount->getId(),
            sourceAmount: '9500.00',
            targetAmount: '100.00',
            occurredAt: new \DateTimeImmutable('2026-08-09'),
            idempotencyKey: 'create-transfer-before-opening',
        ));
    }

    public function testRejectsClosedFinancialPeriod(): void
    {
        [$company, $sourceAccount, $targetAccount] = $this->fixture('RUB', 'USD');
        $company->setFinanceLockBefore(new \DateTimeImmutable('2026-08-10'));
        $this->em->flush();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Период закрыт.');

        $this->cashFacade->createTransfer(new CreateCashTransferCommand(
            companyId: (string) $company->getId(),
            sourceAccountId: (string) $sourceAccount->getId(),
            targetAccountId: (string) $targetAccount->getId(),
            sourceAmount: '9500.00',
            targetAmount: '100.00',
            occurredAt: new \DateTimeImmutable('2026-08-09'),
            idempotencyKey: 'create-transfer-locked',
        ));
    }

    public function testRequiresStrictTechnicalCategories(): void
    {
        [$company, $sourceAccount, $targetAccount] = $this->fixture('RUB', 'USD', false);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Системная категория ДДС CF_TECH_OUT не настроена.');

        $this->cashFacade->createTransfer(new CreateCashTransferCommand(
            companyId: (string) $company->getId(),
            sourceAccountId: (string) $sourceAccount->getId(),
            targetAccountId: (string) $targetAccount->getId(),
            sourceAmount: '9500.00',
            targetAmount: '100.00',
            occurredAt: new \DateTimeImmutable('2026-08-09'),
            idempotencyKey: 'create-transfer-categories',
        ));
    }

    public function testCreationIsIdempotentAndRunsTransferSideEffectsOnce(): void
    {
        [$company, $sourceAccount, $targetAccount] = $this->fixture('RUB', 'USD');
        $date = new \DateTimeImmutable('2026-08-09');
        $command = new CreateCashTransferCommand(
            companyId: (string) $company->getId(),
            sourceAccountId: (string) $sourceAccount->getId(),
            targetAccountId: (string) $targetAccount->getId(),
            sourceAmount: '9500.00',
            targetAmount: '100.00',
            occurredAt: $date,
            idempotencyKey: 'create-transfer-idempotent',
        );
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async_pipeline');
        $transport->reset();
        $cacheInvalidator = self::getContainer()->get(SnapshotCacheInvalidator::class);
        $versionBefore = $cacheInvalidator->resolveVersionForCompany($company);

        $created = $this->cashFacade->createTransfer($command);
        $versionAfterCreate = $cacheInvalidator->resolveVersionForCompany($company);
        $duplicate = $this->cashFacade->createTransfer($command);
        $versionAfterDuplicate = $cacheInvalidator->resolveVersionForCompany($company);

        self::assertTrue($created->created);
        self::assertTrue($duplicate->duplicate);
        self::assertSame($created->transferId, $duplicate->transferId);
        self::assertSame($created->sourceTransactionId, $duplicate->sourceTransactionId);
        self::assertSame($created->targetTransactionId, $duplicate->targetTransactionId);
        self::assertSame($versionBefore + 1, $versionAfterCreate);
        self::assertSame($versionAfterCreate, $versionAfterDuplicate);
        self::assertSame(1, $this->transferRepository->count(['company' => $company]));
        self::assertSame(2, $this->transactionRepository->count(['company' => $company]));
        self::assertCount(0, $transport->getSent(), 'Технические ноги не должны запускать автоправила.');

        $source = $this->transactionRepository->find($created->sourceTransactionId);
        $target = $this->transactionRepository->find($created->targetTransactionId);
        self::assertNotNull($source);
        self::assertNotNull($target);
        self::assertNull($source->getVatRatePercent());
        self::assertNull($source->getVatAmount());
        self::assertNull($target->getVatRatePercent());
        self::assertNull($target->getVatAmount());
        /** @var PaymentPlanMatchRepository $paymentPlanMatchRepository */
        $paymentPlanMatchRepository = self::getContainer()->get(PaymentPlanMatchRepository::class);
        self::assertNull($paymentPlanMatchRepository->findOneByTransaction($source));
        self::assertNull($paymentPlanMatchRepository->findOneByTransaction($target));

        /** @var MoneyAccountDailyBalanceRepository $balanceRepository */
        $balanceRepository = self::getContainer()->get(MoneyAccountDailyBalanceRepository::class);
        $sourceBalance = $balanceRepository->findOneBy(['company' => $company, 'moneyAccount' => $sourceAccount, 'date' => $date]);
        $targetBalance = $balanceRepository->findOneBy(['company' => $company, 'moneyAccount' => $targetAccount, 'date' => $date]);
        self::assertSame('-9500.00', $sourceBalance?->getClosingBalance());
        self::assertSame('100.00', $targetBalance?->getClosingBalance());

        $audits = $this->em->getRepository(AuditLog::class)->findBy([
            'entityClass' => CashTransfer::class,
            'entityId' => $created->transferId,
        ]);
        self::assertCount(1, $audits);
    }

    public function testRollsBackBothLegsWhenBalanceRecalculationFails(): void
    {
        [$company, $sourceAccount, $targetAccount] = $this->fixture('RUB', 'USD');
        $tomorrow = new \DateTimeImmutable('tomorrow');
        $sourceAccount->setOpeningBalanceDate($tomorrow);
        $targetAccount->setOpeningBalanceDate($tomorrow);
        $this->em->flush();

        try {
            $this->cashFacade->createTransfer(new CreateCashTransferCommand(
                companyId: (string) $company->getId(),
                sourceAccountId: (string) $sourceAccount->getId(),
                targetAccountId: (string) $targetAccount->getId(),
                sourceAmount: '9500.00',
                targetAmount: '100.00',
                occurredAt: $tomorrow,
                idempotencyKey: 'create-transfer-rollback',
            ));
            self::fail('Expected balance recalculation to reject a future opening date.');
        } catch (OpeningBalanceDateInFutureException) {
            // Expected after both legs have been flushed inside the transaction.
        }

        $connection = $this->em->getConnection();
        $companyId = (string) $company->getId();
        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM cash_transfer WHERE company_id = :companyId',
            ['companyId' => $companyId],
        ));
        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM cash_transaction WHERE company_id = :companyId',
            ['companyId' => $companyId],
        ));
        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE company_id = :companyId AND entity_class IN (:transferClass, :transactionClass)',
            [
                'companyId' => $companyId,
                'transferClass' => CashTransfer::class,
                'transactionClass' => \App\Cash\Entity\Transaction\CashTransaction::class,
            ],
        ));
    }

    /**
     * @return array{Company, MoneyAccount, MoneyAccount}
     */
    private function fixture(
        string $sourceCurrency,
        string $targetCurrency,
        bool $withCategories = true,
        MoneyAccountType $sourceType = MoneyAccountType::BANK,
        MoneyAccountType $targetType = MoneyAccountType::BANK,
    ): array {
        $user = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail('transfer-'.Uuid::uuid4().'@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(Uuid::uuid4()->toString())
            ->withOwner($user)
            ->withName('Transfer company '.Uuid::uuid4())
            ->build();
        $sourceAccount = new MoneyAccount(
            Uuid::uuid4()->toString(),
            $company,
            $sourceType,
            'Source '.$sourceCurrency.' '.Uuid::uuid4(),
            $sourceCurrency,
        );
        $targetAccount = new MoneyAccount(
            Uuid::uuid4()->toString(),
            $company,
            $targetType,
            'Target '.$targetCurrency.' '.Uuid::uuid4(),
            $targetCurrency,
        );
        $sourceAccount->setOpeningBalanceDate(new \DateTimeImmutable('2026-01-01'));
        $targetAccount->setOpeningBalanceDate(new \DateTimeImmutable('2026-01-01'));
        $project = new ProjectDirection(
            Uuid::uuid4()->toString(),
            $company,
            'Общий',
            ProjectDirection::CODE_GENERAL,
        );
        $center = new FinancialResponsibilityCenter(
            (string) $company->getId(),
            FinancialResponsibilityCenter::CODE_GENERAL,
            FinancialResponsibilityCenter::NAME_GENERAL,
        );

        foreach ([$user, $company, $sourceAccount, $targetAccount, $project, $center] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->persist(new FinancialResponsibilityCenterProject((string) $company->getId(), $project, $center));
        $this->em->flush();
        if ($withCategories) {
            self::getContainer()->get(CashflowSystemCategoryService::class)->ensureStructure($company);
            $this->em->flush();
        }

        return [$company, $sourceAccount, $targetAccount];
    }
}

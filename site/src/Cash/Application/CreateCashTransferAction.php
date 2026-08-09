<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Analytics\Infrastructure\Cache\SnapshotCacheInvalidator;
use App\Cash\Application\DTO\CreateCashTransferCommand;
use App\Cash\Application\DTO\CreateCashTransferResult;
use App\Cash\Application\Service\AutoRuleDispatchGuard;
use App\Cash\Application\Service\CashTransactionResponsibilityCenterResolver;
use App\Cash\Application\Service\DailyBalanceRecalculator;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Entity\Transfer\CashTransfer;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashflowCategoryStatus;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Cash\Exception\FinancePeriodLockedException;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Cash\Repository\Transfer\CashTransferRepository;
use App\Cash\Service\Transfer\EffectiveExchangeRateCalculator;
use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Company\Facade\CompanyFacade;
use App\Shared\Audit\AuditContextProvider;
use App\Shared\Domain\ValueObject\Money;
use App\Shared\Entity\AuditLog;
use App\Shared\Enum\AuditLogAction;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final readonly class CreateCashTransferAction
{
    public function __construct(
        private CompanyFacade $companyFacade,
        private MoneyAccountRepository $moneyAccountRepository,
        private CashflowCategoryRepository $cashflowCategoryRepository,
        private CashTransferRepository $cashTransferRepository,
        private CashTransactionResponsibilityCenterResolver $responsibilityCenterResolver,
        private EffectiveExchangeRateCalculator $rateCalculator,
        private AutoRuleDispatchGuard $autoRuleDispatchGuard,
        private DailyBalanceRecalculator $recalculator,
        private SnapshotCacheInvalidator $snapshotCacheInvalidator,
        private AuditContextProvider $auditContextProvider,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(CreateCashTransferCommand $command): CreateCashTransferResult
    {
        $company = $this->requireCompany($command->companyId);
        $existing = $this->cashTransferRepository->findOneByCompanyIdAndIdempotencyKey(
            $command->companyId,
            $this->normalizeIdempotencyKey($command->idempotencyKey),
        );
        if (null !== $existing) {
            return $this->duplicateResult($existing);
        }

        $sourceAccount = $this->requireAccount($command->sourceAccountId, $command->companyId, 'Счёт списания не найден.');
        $targetAccount = $this->requireAccount($command->targetAccountId, $command->companyId, 'Счёт назначения не найден.');
        $this->assertAccounts($sourceAccount, $targetAccount, $command->occurredAt);
        $this->assertPeriodIsOpen($company, $command->occurredAt);

        $rate = $this->rateCalculator->calculate(
            $command->sourceAmount,
            $sourceAccount->getCurrency(),
            $command->targetAmount,
            $targetAccount->getCurrency(),
            $command->occurredAt,
        );
        $sourceAmount = Money::fromString($command->sourceAmount, $sourceAccount->getCurrency())->toDecimalString();
        $targetAmount = Money::fromString($command->targetAmount, $targetAccount->getCurrency())->toDecimalString();
        $technicalOut = $this->requireTechnicalCategory($company, CashflowCategory::CODE_TECHNICAL_OUT);
        $technicalIn = $this->requireTechnicalCategory($company, CashflowCategory::CODE_TECHNICAL_IN);
        $responsibilityPair = $this->responsibilityCenterResolver->resolveForCreate($command->companyId, null, null);
        $systemProject = $this->companyFacade->findProjectDirectionByIdAndCompany(
            $responsibilityPair->projectDirectionId,
            $command->companyId,
        ) ?? throw new \DomainException('Системный проект компании не найден.');
        $idempotencyKey = $this->normalizeIdempotencyKey($command->idempotencyKey);

        $result = $this->autoRuleDispatchGuard->suppress(
            fn (): CreateCashTransferResult => $this->entityManager->wrapInTransaction(
                function () use (
                    $command,
                    $company,
                    $sourceAccount,
                    $targetAccount,
                    $sourceAmount,
                    $targetAmount,
                    $technicalOut,
                    $technicalIn,
                    $systemProject,
                    $responsibilityPair,
                    $rate,
                    $idempotencyKey,
                ): CreateCashTransferResult {
                    $this->acquireIdempotencyLock($command->companyId, $idempotencyKey);
                    $existing = $this->cashTransferRepository->findOneByCompanyIdAndIdempotencyKey(
                        $command->companyId,
                        $idempotencyKey,
                    );
                    if (null !== $existing) {
                        return $this->duplicateResult($existing);
                    }

                    $source = $this->createLeg(
                        $company,
                        $sourceAccount,
                        CashDirection::OUTFLOW,
                        $sourceAmount,
                        $command->occurredAt,
                        $command->description,
                        $technicalOut,
                        $systemProject,
                        $responsibilityPair->responsibilityCenterId,
                    );
                    $target = $this->createLeg(
                        $company,
                        $targetAccount,
                        CashDirection::INFLOW,
                        $targetAmount,
                        $command->occurredAt,
                        $command->description,
                        $technicalIn,
                        $systemProject,
                        $responsibilityPair->responsibilityCenterId,
                    );
                    $transfer = new CashTransfer(
                        Uuid::uuid7()->toString(),
                        $company,
                        $source,
                        $target,
                        $idempotencyKey,
                        $rate?->value(),
                        $rate?->baseCurrency()->value,
                        $rate?->quoteCurrency()->value,
                        $rate?->date(),
                        $rate?->source(),
                    );

                    $this->entityManager->persist($source);
                    $this->entityManager->persist($target);
                    $this->entityManager->persist($transfer);
                    $this->entityManager->persist($this->createAudit($transfer));
                    $this->entityManager->flush();
                    // CashTransaction audit subscribers schedule their CREATE rows
                    // during postPersist, so a second flush keeps them in this transaction.
                    $this->entityManager->flush();

                    $accountIds = [(string) $sourceAccount->getId(), (string) $targetAccount->getId()];
                    sort($accountIds, \SORT_STRING);
                    foreach ($accountIds as $accountId) {
                        $this->recalculator->recalcRange(
                            $company,
                            $command->occurredAt->setTime(0, 0),
                            (new \DateTimeImmutable('today'))->setTime(0, 0),
                            [$accountId],
                        );
                    }

                    return new CreateCashTransferResult(
                        $transfer->getId(),
                        (string) $source->getId(),
                        (string) $target->getId(),
                        true,
                        false,
                    );
                },
            ),
        );

        if ($result->created) {
            // Redis/cache mutation happens only after the database transaction commits.
            $this->snapshotCacheInvalidator->invalidateForCompany($company);
        }

        return $result;
    }

    private function requireCompany(string $companyId): Company
    {
        if (!Uuid::isValid($companyId)) {
            throw new \DomainException('Компания не найдена.');
        }

        return $this->companyFacade->findById($companyId)
            ?? throw new \DomainException('Компания не найдена.');
    }

    private function requireAccount(string $accountId, string $companyId, string $message): MoneyAccount
    {
        if (!Uuid::isValid($accountId)) {
            throw new \DomainException($message);
        }

        return $this->moneyAccountRepository->findOneByIdAndCompanyId($accountId, $companyId)
            ?? throw new \DomainException($message);
    }

    private function assertAccounts(
        MoneyAccount $sourceAccount,
        MoneyAccount $targetAccount,
        \DateTimeImmutable $occurredAt,
    ): void {
        if ($sourceAccount->getId() === $targetAccount->getId()) {
            throw new \DomainException('Счета перевода должны различаться.');
        }

        foreach ([$sourceAccount, $targetAccount] as $account) {
            if (!$account->isActive()) {
                throw new \DomainException('Переводы разрешены только между активными счетами.');
            }
            if (MoneyAccountType::CRYPTO_WALLET === $account->getType()) {
                throw new \DomainException('Криптовалютные счета не поддерживаются переводами ДДС.');
            }
            if ($occurredAt->setTime(0, 0) < $account->getOpeningBalanceDate()->setTime(0, 0)) {
                throw new \DomainException('Дата перевода не может быть раньше даты открытия счёта.');
            }
        }
    }

    private function assertPeriodIsOpen(Company $company, \DateTimeImmutable $occurredAt): void
    {
        $lock = $company->getFinanceLockBefore()?->setTime(0, 0);
        if (null !== $lock && $occurredAt->setTime(0, 0) < $lock) {
            throw new FinancePeriodLockedException(sprintf('Период закрыт. Операции с датами ранее %s запрещены.', $lock->format('d.m.Y')));
        }
    }

    private function requireTechnicalCategory(Company $company, string $code): CashflowCategory
    {
        $category = $this->cashflowCategoryRepository->findOneByCompanyAndCode($company, $code);
        if (null === $category
            || !$category->isSystem()
            || CashflowCategoryStatus::ACTIVE !== $category->getStatus()
            || CashflowCategory::CODE_TECHNICAL !== $category->getParent()?->getSystemCode()
            || !$category->getParent()->isSystem()) {
            throw new \DomainException(sprintf('Системная категория ДДС %s не настроена.', $code));
        }

        return $category;
    }

    private function createLeg(
        Company $company,
        MoneyAccount $account,
        CashDirection $direction,
        string $amount,
        \DateTimeImmutable $occurredAt,
        ?string $description,
        CashflowCategory $category,
        ProjectDirection $project,
        string $responsibilityCenterId,
    ): CashTransaction {
        $transaction = new CashTransaction(
            Uuid::uuid7()->toString(),
            $company,
            $account,
            $direction,
            $amount,
            $account->getCurrency(),
            $occurredAt,
        );
        $transaction
            ->setDescription($description)
            ->setCashflowCategory($category)
            ->setProjectDirection($project)
            ->setResponsibilityCenterId($responsibilityCenterId)
            ->setIsTransfer(true);
        $transaction->replaceSplits([
            new CashTransactionSplit($transaction, $category, $amount, CashTransactionSplitSource::MANUAL),
        ]);

        return $transaction;
    }

    private function createAudit(CashTransfer $transfer): AuditLog
    {
        return new AuditLog(
            (string) $transfer->getCompany()->getId(),
            CashTransfer::class,
            $transfer->getId(),
            AuditLogAction::CREATE,
            [
                'sourceTransactionId' => $transfer->getSourceTransaction()->getId(),
                'targetTransactionId' => $transfer->getTargetTransaction()->getId(),
                'effectiveRate' => $transfer->getEffectiveRate(),
                'rateBaseCurrency' => $transfer->getRateBaseCurrency(),
                'rateQuoteCurrency' => $transfer->getRateQuoteCurrency(),
            ],
            $this->resolveActorUserId(),
        );
    }

    private function resolveActorUserId(): ?string
    {
        try {
            return $this->auditContextProvider->getActorUserId();
        } catch (\Throwable) {
            return null;
        }
    }

    private function acquireIdempotencyLock(string $companyId, string $idempotencyKey): void
    {
        $this->entityManager->getConnection()->executeQuery(
            'SELECT pg_advisory_xact_lock(hashtextextended(:lockKey, 0))',
            ['lockKey' => $companyId.':'.$idempotencyKey],
        );
    }

    private function normalizeIdempotencyKey(string $idempotencyKey): string
    {
        $idempotencyKey = trim($idempotencyKey);
        if ('' === $idempotencyKey || strlen($idempotencyKey) > 128) {
            throw new \DomainException('Некорректный ключ идемпотентности перевода.');
        }

        return $idempotencyKey;
    }

    private function duplicateResult(CashTransfer $transfer): CreateCashTransferResult
    {
        return new CreateCashTransferResult(
            $transfer->getId(),
            (string) $transfer->getSourceTransaction()->getId(),
            (string) $transfer->getTargetTransaction()->getId(),
            false,
            true,
        );
    }
}

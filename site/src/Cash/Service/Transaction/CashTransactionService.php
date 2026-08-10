<?php

declare(strict_types=1);

namespace App\Cash\Service\Transaction;

use App\Analytics\Infrastructure\Cache\SnapshotCacheInvalidator;
use App\Cash\Application\Service\CashTransactionResponsibilityCenterResolver;
use App\Cash\Application\Service\DailyBalanceRecalculator;
use App\Cash\DTO\CashTransactionDTO;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\FiatCurrency;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Cash\Exception\CurrencyMismatchException;
use App\Cash\Exception\FinancePeriodLockedException;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Repository\Transfer\CashTransferRepository;
use App\Cash\Service\PaymentPlan\PaymentPlanMatcher;
use App\Cash\Service\Vat\VatCalculator;
use App\Cash\Service\Vat\VatPolicy;
use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Company\Entity\ProjectDirection;
use App\Company\Facade\CompanyFacade;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Ramsey\Uuid\Uuid;

final class CashTransactionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DailyBalanceRecalculator $recalculator,
        private readonly CashTransactionRepository $txRepo,
        private readonly PaymentPlanMatcher $paymentPlanMatcher,
        private readonly VatPolicy $vatPolicy,
        private readonly VatCalculator $vatCalculator,
        private readonly SnapshotCacheInvalidator $snapshotCacheInvalidator,
        private readonly CashTransactionResponsibilityCenterResolver $responsibilityCenterResolver,
        private readonly CashTransactionSplitSynchronizer $splitSynchronizer,
        private readonly CompanyFacade $companyFacade,
        private readonly MoneyAccountRepository $moneyAccountRepository,
        private readonly CashflowCategoryRepository $cashflowCategoryRepository,
        private readonly CashTransferRepository $cashTransferRepository,
    ) {
    }

    /**
     * Создание транзакции ДДС.
     * Важно: сперва пишем транзакцию (flush), затем — пересчитываем факты, чтобы расчёт шёл по актуальным данным.
     *
     * @throws ORMException
     */
    public function add(CashTransactionDTO $dto): CashTransaction
    {
        $company = $this->resolveCompany($dto->companyId);
        $account = $this->resolveAccount($dto->moneyAccountId, $dto->companyId);
        $currency = $this->resolveCurrencyForAccount($dto->currency, $account);
        $counterparty = $this->resolveCounterparty($dto->counterpartyId, $dto->companyId);
        $category = $this->resolveCategory($dto->cashflowCategoryId, $dto->companyId);

        // --- Безопасность: запрет операций в закрытом периоде компании
        $this->assertNotLockedForCompany($company, $dto->occurredAt);

        $existingTransaction = null;
        if (null !== $dto->importSource && null !== $dto->externalId) {
            $existingTransaction = $this->txRepo->findOneByImport(
                $dto->companyId,
                $dto->importSource,
                $dto->externalId,
            );
        }

        if ($existingTransaction instanceof CashTransaction) {
            $changed = false;

            if ($dto->description !== $existingTransaction->getDescription()) {
                $existingTransaction->setDescription($dto->description);
                $changed = true;
            }

            $existingCounterparty = $existingTransaction->getCounterparty();
            $existingCounterpartyId = $existingCounterparty?->getId();

            if (null !== $dto->counterpartyId) {
                if ($existingCounterpartyId !== $dto->counterpartyId) {
                    $existingTransaction->setCounterparty($counterparty);
                    $changed = true;
                }
            } elseif (null !== $existingCounterparty) {
                $existingTransaction->setCounterparty(null);
                $changed = true;
            }

            if ($changed) {
                $this->em->flush();
            }

            return $existingTransaction;
        }

        // Создаём сущность транзакции
        $tx = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            $dto->direction,
            $dto->amount,
            $currency,
            $dto->occurredAt
        );

        // Опциональные связи
        $responsibilityPair = $this->responsibilityCenterResolver->resolveForCreate(
            $dto->companyId,
            $dto->projectDirectionId,
            $dto->responsibilityCenterId,
        );
        $projectDirection = $this->resolveProjectDirection($responsibilityPair->projectDirectionId, $dto->companyId);

        $tx
            ->setDescription($dto->description)
            ->setCounterparty($counterparty)
            ->setCashflowCategory($category)
            ->setProjectDirection($projectDirection)
            ->setResponsibilityCenterId($responsibilityPair->responsibilityCenterId);

        $tx->setImportSource($dto->importSource);
        $tx->setExternalId($dto->externalId);
        $tx->setDedupeHash($dto->dedupeHash);
        $tx->setRawData($dto->rawData ?? []);

        $this->applyVat($tx, $company, $dto->direction, $dto->amount);

        $this->splitSynchronizer->sync($tx, CashTransactionSplitSource::MANUAL);

        // Сохраняем
        $this->em->persist($tx);
        $this->em->flush(); // ← flush перед пересчётом обязателен

        $this->paymentPlanMatcher->matchForTransaction($tx);

        // После фиксации транзакции обязательно пересчитываем факты и инвалидируем snapshot:
        // виджеты dashboard читают агрегированные данные, поэтому без инвалидации пользователь
        // может увидеть устаревший слепок в пределах TTL даже после успешного сохранения.
        $from = $dto->occurredAt->setTime(0, 0);
        $to = (new \DateTimeImmutable('today'))->setTime(0, 0); // правая граница — сегодня, дальше DailyBalanceRecalculator сам расширит до макс. факта
        $this->recalculator->recalcRange($company, $from, $to, [$account->getId()]);
        $this->snapshotCacheInvalidator->invalidateForCompany($company);

        return $tx;
    }

    /**
     * Редактирование транзакции.
     * Пересчитываем минимально возможный диапазон (с минимальной из старой/новой даты).
     */
    public function update(CashTransaction $tx, CashTransactionDTO $dto): CashTransaction
    {
        $this->assertStandaloneTransaction($tx);

        $company = $tx->getCompany();
        $oldAccount = $tx->getMoneyAccount();
        $oldDate = $tx->getOccurredAt();

        // --- Безопасность: нельзя править/переносить транзакцию в закрытый период
        $this->assertNotLockedForCompany($company, $oldDate);
        $this->assertNotLockedForCompany($company, $dto->occurredAt);

        $companyId = (string) $company->getId();
        $account = $this->resolveAccount($dto->moneyAccountId, $companyId);
        $currency = $this->resolveCurrencyForAccount($dto->currency, $account);

        // Обновляем поля
        $tx->setMoneyAccount($account)
            ->setDirection($dto->direction)
            ->setAmount($dto->amount)
            ->setCurrency($currency)
            ->setOccurredAt($dto->occurredAt)
            ->setDescription($dto->description);

        $counterparty = $this->resolveCounterparty($dto->counterpartyId, $companyId);
        $category = $this->resolveCategory($dto->cashflowCategoryId, $companyId);
        $responsibilityPair = $this->responsibilityCenterResolver->resolveChangedPairForUpdate(
            $companyId,
            $tx->getProjectDirection()?->getId(),
            $tx->getResponsibilityCenterId(),
            $dto->projectDirectionId ?? $tx->getProjectDirection()?->getId(),
            $dto->responsibilityCenterId ?? $tx->getResponsibilityCenterId(),
        );
        $projectDirection = null !== $responsibilityPair
            ? $this->resolveProjectDirection($responsibilityPair->projectDirectionId, $companyId)
            : $tx->getProjectDirection();
        $responsibilityCenterId = null !== $responsibilityPair
            ? $responsibilityPair->responsibilityCenterId
            : $tx->getResponsibilityCenterId();

        $tx->setCounterparty($counterparty)
            ->setCashflowCategory($category)
            ->setProjectDirection($projectDirection)
            ->setResponsibilityCenterId($responsibilityCenterId);

        $this->applyVat($tx, $company, $dto->direction, $dto->amount);

        $this->splitSynchronizer->sync($tx, CashTransactionSplitSource::MANUAL);

        // Сохраняем изменения
        $this->em->flush(); // ← flush перед пересчётом обязателен

        // Минимальный диапазон пересчёта: от min(старая дата, новая дата) до сегодня
        $from = min($dto->occurredAt, $oldDate)->setTime(0, 0);
        $to = (new \DateTimeImmutable('today'))->setTime(0, 0);

        // После обновления пересчитываем факты по затронутым счетам, а затем инвалидируем snapshot,
        // чтобы следующий запрос dashboard гарантированно строился на новой версии данных.
        // Это защищает от ситуации, когда расчёт уже завершился, но API ещё отдаёт кэш старой версии.
        // Пересчёт по старому счёту (на случай переноса даты/суммы/направления)
        $this->recalculator->recalcRange($company, $from, $to, [$oldAccount->getId()]);

        // Если счёт изменили — пересчитываем и по новому счёту
        if ($oldAccount->getId() !== $account->getId()) {
            $this->recalculator->recalcRange($company, $from, $to, [$account->getId()]);
        }

        $this->snapshotCacheInvalidator->invalidateForCompany($company);

        return $tx;
    }

    private function applyVat(CashTransaction $tx, Company $company, CashDirection $direction, string $amount): void
    {
        $rate = $this->vatPolicy->decideForCash($company, $direction);

        if (null === $rate) {
            $tx->setVatRatePercent(null);
            $tx->setVatAmount(null);

            return;
        }

        $split = $this->vatCalculator->splitGross((float) $amount, $rate);
        $tx->setVatRatePercent($rate);
        $tx->setVatAmount($split['vat']);
    }

    private function resolveCompany(string $companyId): Company
    {
        if (!Uuid::isValid($companyId)) {
            throw new \DomainException('Компания не найдена.');
        }

        return $this->companyFacade->findById($companyId)
            ?? throw new \DomainException('Компания не найдена.');
    }

    private function resolveAccount(string $accountId, string $companyId): MoneyAccount
    {
        if (!Uuid::isValid($accountId)) {
            throw new \DomainException('Счёт не найден.');
        }

        return $this->moneyAccountRepository->findOneByIdAndCompanyId($accountId, $companyId)
            ?? throw new \DomainException('Счёт не найден.');
    }

    private function resolveCounterparty(?string $counterpartyId, string $companyId): ?Counterparty
    {
        if (null === $counterpartyId) {
            return null;
        }

        return $this->companyFacade->findCounterpartyByIdAndCompany($counterpartyId, $companyId)
            ?? throw new \DomainException('Контрагент не найден.');
    }

    private function resolveCategory(?string $categoryId, string $companyId): ?CashflowCategory
    {
        if (null === $categoryId) {
            return null;
        }

        if (!Uuid::isValid($categoryId)) {
            throw new \DomainException('Категория ДДС не найдена.');
        }

        return $this->cashflowCategoryRepository->findOneByIdAndCompanyId($categoryId, $companyId)
            ?? throw new \DomainException('Категория ДДС не найдена.');
    }

    private function resolveProjectDirection(string $projectDirectionId, string $companyId): ProjectDirection
    {
        return $this->companyFacade->findProjectDirectionByIdAndCompany($projectDirectionId, $companyId)
            ?? throw new \DomainException('Проект не найден.');
    }

    private function resolveCurrencyForAccount(string $currency, MoneyAccount $account): string
    {
        $normalized = FiatCurrency::fromCode($currency)->value;
        if ($normalized !== $account->getCurrency()) {
            throw new CurrencyMismatchException('Валюта транзакции должна совпадать с валютой счёта.');
        }

        return $account->getCurrency();
    }

    /**
     * Удаление транзакции.
     * Сначала удаляем и фиксируем это в БД, затем запускаем пересчёт по затронутому счёту.
     */
    public function delete(CashTransaction $tx, ?string $userId = null, ?string $reason = null): void
    {
        $this->assertStandaloneTransaction($tx);

        $company = $tx->getCompany();

        // --- Безопасность: нельзя удалять транзакцию из закрытого периода
        $this->assertNotLockedForCompany($company, $tx->getOccurredAt());

        $account = $tx->getMoneyAccount();

        // Диапазон пересчёта
        $from = $tx->getOccurredAt()->setTime(0, 0);
        $to = (new \DateTimeImmutable('today'))->setTime(0, 0);

        // Удаляем и фиксируем
        $tx->markDeleted($userId, $reason);
        $this->em->flush(); // ← flush перед пересчётом обязателен

        // Для удаления действуем так же: сначала фиксируем изменение, потом обновляем факты,
        // и только после этого повышаем версию snapshot cache. Такой порядок гарантирует,
        // что новый cache key укажет на уже актуальное состояние регистров и остатков.
        // Пересчёт по счёту
        $this->recalculator->recalcRange($company, $from, $to, [$account->getId()]);
        $this->snapshotCacheInvalidator->invalidateForCompany($company);
    }

    /**
     * Восстановление транзакции.
     * Сначала восстанавливаем и фиксируем это в БД, затем запускаем пересчёт по затронутому счёту.
     */
    public function restore(CashTransaction $tx): void
    {
        $this->assertStandaloneTransaction($tx);

        $company = $tx->getCompany();

        // --- Безопасность: нельзя восстанавливать транзакцию из закрытого периода
        $this->assertNotLockedForCompany($company, $tx->getOccurredAt());

        $account = $tx->getMoneyAccount();

        // Диапазон пересчёта
        $from = $tx->getOccurredAt()->setTime(0, 0);
        $to = (new \DateTimeImmutable('today'))->setTime(0, 0);

        // Восстанавливаем и фиксируем
        $tx->restore();
        $this->em->flush(); // ← flush перед пересчётом обязателен

        // Пересчёт по счёту
        $this->recalculator->recalcRange($company, $from, $to, [$account->getId()]);
    }

    public function assertStandaloneTransaction(CashTransaction $transaction): void
    {
        if (null !== $this->cashTransferRepository->findOneByTransactionAndCompanyId(
            $transaction,
            (string) $transaction->getCompany()->getId(),
        )) {
            throw new \DomainException('Операцию перевода нельзя изменить отдельно. Откройте связанный перевод.');
        }
    }

    /**
     * Жёсткая проверка «замка периода» на уровне компании.
     * Если в Company задана дата financeLockBefore, то любые операции с датой строго ранее этой даты запрещены.
     * Бросаем DomainException — контроллер покажет сообщение пользователю.
     */
    private function assertNotLockedForCompany(Company $company, \DateTimeInterface $date): void
    {
        $lock = $company->getFinanceLockBefore();
        if (!$lock) {
            return; // замка нет — ничего не запрещаем
        }

        $lock = $lock->setTime(0, 0);
        $current = $date instanceof \DateTimeImmutable
            ? $date->setTime(0, 0)
            : \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);

        if ($current < $lock) {
            throw new FinancePeriodLockedException(sprintf('Период закрыт. Операции с датами ранее %s запрещены.', $lock->format('d.m.Y')));
        }
    }
}

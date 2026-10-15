<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Application\Service\CashTransactionResponsibilityCenterResolver;
use App\Cash\Application\Service\DailyBalanceRecalculator;
use App\Cash\Domain\Event\CashTransactionsChanged;
use App\Cash\DTO\CashTransactionDTO;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Cash\Service\Transaction\CashTransactionService;
use App\Cash\Service\Transaction\CashTransactionSplitSynchronizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class UpdateCashTransactionAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CashTransactionService $transactions,
        private readonly DailyBalanceRecalculator $recalculator,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly CashTransactionResponsibilityCenterResolver $responsibilityCenterResolver,
        private readonly CashTransactionSplitSynchronizer $splitSynchronizer,
    ) {
    }

    /**
     * Редактирование транзакции.
     * Пересчитываем минимально возможный диапазон (с минимальной из старой/новой даты).
     */
    public function __invoke(CashTransaction $tx, CashTransactionDTO $dto): CashTransaction
    {
        $this->transactions->assertStandaloneTransaction($tx);

        $company = $tx->getCompany();
        $oldAccount = $tx->getMoneyAccount();
        $oldDate = $tx->getOccurredAt();

        // --- Безопасность: нельзя править/переносить транзакцию в закрытый период
        $this->transactions->assertNotLockedForCompany($company, $oldDate);
        $this->transactions->assertNotLockedForCompany($company, $dto->occurredAt);

        $companyId = (string) $company->getId();
        $account = $this->transactions->resolveAccount($dto->moneyAccountId, $companyId);
        $currency = $this->transactions->resolveCurrencyForAccount($dto->currency, $account);

        // Обновляем поля
        $amount = $this->transactions->requireAmount($dto);
        $tx->setMoneyAccount($account)
            ->setDirection($dto->direction)
            ->setAmount($amount)
            ->setCurrency($currency)
            ->setOccurredAt($dto->occurredAt)
            ->setDescription($dto->description);

        $counterparty = $this->transactions->resolveCounterparty($dto->counterpartyId, $companyId);
        $category = $this->transactions->resolveCategory($dto->cashflowCategoryId, $companyId);
        $responsibilityPair = $this->responsibilityCenterResolver->resolveChangedPairForUpdate(
            $companyId,
            $tx->getProjectDirection()?->getId(),
            $tx->getResponsibilityCenterId(),
            $dto->projectDirectionId ?? $tx->getProjectDirection()?->getId(),
            $dto->responsibilityCenterId ?? $tx->getResponsibilityCenterId(),
        );
        $projectDirection = null !== $responsibilityPair
            ? $this->transactions->resolveProjectDirection($responsibilityPair->projectDirectionId, $companyId)
            : $tx->getProjectDirection();
        $responsibilityCenterId = null !== $responsibilityPair
            ? $responsibilityPair->responsibilityCenterId
            : $tx->getResponsibilityCenterId();

        $tx->setCounterparty($counterparty)
            ->setCashflowCategory($category)
            ->setProjectDirection($projectDirection)
            ->setResponsibilityCenterId($responsibilityCenterId);

        $this->transactions->applyVat($tx, $company, $dto->direction, $amount);

        $this->splitSynchronizer->sync($tx, CashTransactionSplitSource::MANUAL);

        // Сохраняем изменения
        $this->entityManager->flush(); // ← flush перед пересчётом обязателен

        // Минимальный диапазон пересчёта: от min(старая дата, новая дата) до сегодня
        $from = min($dto->occurredAt, $oldDate)->setTime(0, 0);
        $to = (new \DateTimeImmutable('today'))->setTime(0, 0);

        // После обновления пересчитываем факты по затронутым счетам, а затем инвалидируем snapshot,
        // чтобы следующий запрос dashboard гарантированно строился на новой версии данных.
        // Это защищает от ситуации, когда расчёт уже завершился, но API ещё отдаёт кэш старой версии.
        // Пересчёт по старому счёту (на случай переноса даты/суммы/направления)
        $this->recalculator->recalcRange($company, $from, $to, [(string) $oldAccount->getId()]);

        // Если счёт изменили — пересчитываем и по новому счёту
        if ($oldAccount->getId() !== $account->getId()) {
            $this->recalculator->recalcRange($company, $from, $to, [(string) $account->getId()]);
        }

        $this->eventDispatcher->dispatch(new CashTransactionsChanged((string) $company->getId()));

        return $tx;
    }
}

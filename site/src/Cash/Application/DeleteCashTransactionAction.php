<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Analytics\Infrastructure\Cache\SnapshotCacheInvalidator;
use App\Cash\Application\Service\DailyBalanceRecalculator;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Service\Transaction\CashTransactionService;
use Doctrine\ORM\EntityManagerInterface;

final class DeleteCashTransactionAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CashTransactionService $transactions,
        private readonly DailyBalanceRecalculator $recalculator,
        private readonly SnapshotCacheInvalidator $snapshotCacheInvalidator,
    ) {
    }

    /**
     * Удаление транзакции.
     * Сначала удаляем и фиксируем это в БД, затем запускаем пересчёт по затронутому счёту.
     */
    public function __invoke(CashTransaction $tx, ?string $userId = null, ?string $reason = null): void
    {
        $this->transactions->assertStandaloneTransaction($tx);

        $company = $tx->getCompany();

        // --- Безопасность: нельзя удалять транзакцию из закрытого периода
        $this->transactions->assertNotLockedForCompany($company, $tx->getOccurredAt());

        $account = $tx->getMoneyAccount();

        // Диапазон пересчёта
        $from = $tx->getOccurredAt()->setTime(0, 0);
        $to = (new \DateTimeImmutable('today'))->setTime(0, 0);

        // Удаляем и фиксируем
        $tx->markDeleted($userId, $reason);
        $this->entityManager->flush(); // ← flush перед пересчётом обязателен

        // Для удаления действуем так же: сначала фиксируем изменение, потом обновляем факты,
        // и только после этого повышаем версию snapshot cache. Такой порядок гарантирует,
        // что новый cache key укажет на уже актуальное состояние регистров и остатков.
        // Пересчёт по счёту
        $this->recalculator->recalcRange($company, $from, $to, [(string) $account->getId()]);
        $this->snapshotCacheInvalidator->invalidateForCompany($company);
    }
}

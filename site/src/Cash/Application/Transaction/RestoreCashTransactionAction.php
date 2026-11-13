<?php

declare(strict_types=1);

namespace App\Cash\Application\Transaction;

use App\Cash\Application\Service\Accounts\DailyBalanceRecalculator;
use App\Cash\Application\Service\Transaction\CashTransactionService;
use App\Cash\Entity\Transaction\CashTransaction;
use Doctrine\ORM\EntityManagerInterface;

final class RestoreCashTransactionAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly \App\Cash\Application\Service\CashSettlementSourceLifecycle $settlementLifecycle,
        private readonly CashTransactionService $transactions,
        private readonly DailyBalanceRecalculator $recalculator,
        private readonly \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $events,
    ) {
    }

    /**
     * Восстановление транзакции.
     * Сначала восстанавливаем и фиксируем это в БД, затем запускаем пересчёт по затронутому счёту.
     */
    public function __invoke(CashTransaction $tx): void
    {
        $this->transactions->assertStandaloneTransaction($tx);

        $this->entityManager->wrapInTransaction(function () use ($tx): void {
            $this->settlementLifecycle->lock($tx);
            $this->settlementLifecycle->changing($tx, true);

            $this->transactions->assertStandaloneTransaction($tx);

            $company = $tx->getCompany();

            // --- Безопасность: нельзя восстанавливать транзакцию из закрытого периода
            $this->transactions->assertNotLockedForCompany($company, $tx->getOccurredAt());

            $account = $tx->getMoneyAccount();

            // Диапазон пересчёта
            $from = $tx->getOccurredAt()->setTime(0, 0);
            $to = (new \DateTimeImmutable('today'))->setTime(0, 0);

            // Восстанавливаем и фиксируем
            $tx->restore();
            $this->entityManager->flush(); // ← flush перед пересчётом обязателен

            // Пересчёт по счёту
            $this->recalculator->recalcRange($company, $from, $to, [(string) $account->getId()]);
        });
        $this->events->dispatch(new \App\Cash\Domain\Event\CashTransactionsChanged((string) $tx->getCompany()->getId()));
    }
}

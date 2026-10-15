<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Application\Service\DailyBalanceRecalculator;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Service\Transaction\CashTransactionService;
use Doctrine\ORM\EntityManagerInterface;

final class RestoreCashTransactionAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CashTransactionService $transactions,
        private readonly DailyBalanceRecalculator $recalculator,
    ) {
    }

    /**
     * Восстановление транзакции.
     * Сначала восстанавливаем и фиксируем это в БД, затем запускаем пересчёт по затронутому счёту.
     */
    public function __invoke(CashTransaction $tx): void
    {
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
    }
}

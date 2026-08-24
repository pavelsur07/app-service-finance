<?php

declare(strict_types=1);

namespace App\Cash\Application\Service;

use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Service\Accounts\AccountBalanceService;
use App\Company\Entity\Company;

/**
 * Выбирает счета компании и делегирует формулу единому сервису остатков.
 */
class DailyBalanceRecalculator
{
    public function __construct(
        private readonly MoneyAccountRepository $accountRepo,
        private readonly AccountBalanceService $accountBalanceService,
    ) {
    }

    /**
     * Пересчёт по компании и (опционально) списку счетов.
     *
     * @param array<string>|null $accountIds
     */
    public function recalcRange(Company $company, \DateTimeImmutable $from, \DateTimeImmutable $to, ?array $accountIds = null): void
    {
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        if ($accountIds && \count($accountIds) > 0) {
            $accounts = $this->accountRepo->createQueryBuilder('a')
                ->where('a.company = :c')->setParameter('c', $company)
                ->andWhere('a.id IN (:ids)')->setParameter('ids', $accountIds)
                ->getQuery()->getResult();
        } else {
            $accounts = $this->accountRepo->findBy(['company' => $company]);
        }

        foreach ($accounts as $account) {
            $this->accountBalanceService->recalculateDailyRange($company, $account, $from, $to);
        }
    }
}

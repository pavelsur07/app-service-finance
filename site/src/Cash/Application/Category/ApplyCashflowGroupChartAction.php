<?php

declare(strict_types=1);

namespace App\Cash\Application\Category;

use App\Cash\Application\DTO\CashflowGroupChartSyncPlan;
use Doctrine\ORM\EntityManagerInterface;

/** «Применить план» с экрана «Эталон группы»: синхронизация в своей транзакции. */
final class ApplyCashflowGroupChartAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SyncCashflowGroupChartAction $sync,
    ) {
    }

    public function __invoke(string $companyId): CashflowGroupChartSyncPlan
    {
        /* @var CashflowGroupChartSyncPlan */
        return $this->entityManager->wrapInTransaction(fn (): CashflowGroupChartSyncPlan => ($this->sync)($companyId));
    }
}

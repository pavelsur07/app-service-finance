<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Application\Reconciliation\OzonReportParserFacade;
use App\Marketplace\Entity\ReconciliationSession;
use App\Marketplace\Infrastructure\Query\CostReconciliationQuery;
use App\Marketplace\Infrastructure\Query\SalesReturnsTotalQuery;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Use-case: запустить сверку xlsx с данными marketplace_costs.
 *
 * Отличие от ReconcileCostsAction: результат пишется в ReconciliationSession,
 * а не в MarketplaceMonthClose.settings.
 */
final class RunUserReconciliationAction
{
    public function __construct(
        private readonly OzonReportParserFacade $parserFacade,
        private readonly CostReconciliationQuery $reconciliationQuery,
        private readonly SalesReturnsTotalQuery $salesReturnsTotalQuery,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(string $companyId, ReconciliationSession $session): void
    {
        try {
            $reportResult = $this->parserFacade->parseFromStoragePath(
                $session->getStoredFilePath(),
            );

            $reconcileResult = $this->reconciliationQuery->reconcile(
                $companyId,
                $session->getMarketplace(),
                $session->getPeriodFrom()->format('Y-m-d'),
                $session->getPeriodTo()->format('Y-m-d'),
                $reportResult,
            );

            $salesTotal = $this->salesReturnsTotalQuery->getSalesTotal(
                $companyId,
                $session->getMarketplace(),
                $session->getPeriodFrom()->format('Y-m-d'),
                $session->getPeriodTo()->format('Y-m-d'),
            );

            // return_revenue is already computed by CostReconciliationQuery — reuse it
            $returnsTotal = (string) ($reconcileResult['return_revenue'] ?? 0);

            $reconcileResult = $this->enrichWithSalesAndReturns(
                $reconcileResult,
                $salesTotal,
                $returnsTotal,
            );

            $reconcileResult = $this->recalculateTotals($reconcileResult);

            $session->markCompleted($reconcileResult);
            $this->em->flush();
        } catch (\Throwable $e) {
            if ($session->getStatus()->isPending()) {
                try {
                    $session->markFailed(mb_substr($e->getMessage(), 0, 1024));
                    $this->em->flush();
                } catch (\Throwable) {
                    // Не маскируем оригинальную ошибку
                }
            }

            throw $e;
        }
    }

    /**
     * Enrich group_comparison with sales and returns totals from dedicated tables.
     *
     * CostReconciliationQuery only covers marketplace_costs.
     * Groups "Продажи" and "Возвраты" in the xlsx have no matching cost categories,
     * so their api_net is 0. This method fills in the real amounts from
     * marketplace_sales.total_revenue and marketplace_returns.refund_amount.
     *
     * @param array<string, mixed> $reconcileResult
     */
    private function enrichWithSalesAndReturns(
        array $reconcileResult,
        string $salesTotal,
        string $returnsTotal,
    ): array {
        $groupComparison = $reconcileResult['group_comparison'] ?? [];

        foreach ($groupComparison as &$group) {
            if ($group['service_group'] === 'Продажи') {
                $group['api_net'] = (float) $salesTotal;
                $group['delta'] = round(abs($group['xlsx_net']) - abs((float) $salesTotal), 2);
                $group['status'] = abs($group['delta']) < 0.01 ? 'matched' : 'mismatch';
            }
            if ($group['service_group'] === 'Возвраты') {
                $group['api_net'] = (float) $returnsTotal;
                $group['delta'] = round(abs($group['xlsx_net']) - abs((float) $returnsTotal), 2);
                $group['status'] = abs($group['delta']) < 0.01 ? 'matched' : 'mismatch';
            }
        }
        unset($group);

        $reconcileResult['group_comparison'] = $groupComparison;

        return $reconcileResult;
    }

    /**
     * Пересчитать итоговые поля (xlsx_total, delta, status) на основе group_comparison.
     *
     * CostReconciliationQuery считает xlsx_total только из отрицательных xlsx-групп,
     * поэтому положительные группы (компенсации) выпадают. Здесь мы пересчитываем
     * итоги как сумму дельт по всем группам — если каждая группа совпала, итог тоже 0.
     *
     * @param array<string, mixed> $reconcileResult
     * @return array<string, mixed>
     */
    private function recalculateTotals(array $reconcileResult): array
    {
        $groupComparison = $reconcileResult['group_comparison'] ?? [];

        $totalDelta  = 0.0;
        $hasMismatch = false;

        foreach ($groupComparison as $group) {
            $totalDelta += abs($group['delta'] ?? 0);
            if (($group['status'] ?? '') === 'mismatch') {
                $hasMismatch = true;
            }
        }

        $reconcileResult['delta']  = round($totalDelta, 2);
        $reconcileResult['status'] = $hasMismatch ? 'mismatch' : 'matched';

        return $reconcileResult;
    }
}

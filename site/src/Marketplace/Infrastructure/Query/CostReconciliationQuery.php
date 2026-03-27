<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Сверяет данные из xlsx-отчёта с данными из marketplace_costs.
 *
 * Формула сверки (та же что в CostsVerifyQuery):
 *   xlsx_comparable = api_net_amount + return_revenue_amount
 *   delta           = |xlsx_comparable| - xlsx_total
 *
 * Статусы:
 *   matched  — |delta| < 0.01
 *   mismatch — |delta| >= 0.01
 */
final class CostReconciliationQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param array<string, mixed> $reportResult ReportResult от OzonReportParserFacade
     * @return array<string, mixed>
     */
    public function reconcile(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
        array $reportResult,
    ): array {
        $apiStats = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT
                SUM(c.amount)                                               AS net_amount,
                SUM(CASE WHEN c.amount > 0 THEN c.amount  ELSE 0 END)     AS costs_amount,
                SUM(CASE WHEN c.amount < 0 THEN ABS(c.amount) ELSE 0 END) AS storno_amount,
                COALESCE((
                    SELECT SUM(r.refund_amount)
                    FROM marketplace_returns r
                    WHERE r.company_id  = :companyId
                      AND r.marketplace = :marketplace
                      AND r.return_date >= :periodFrom
                      AND r.return_date <= :periodTo
                ), 0) AS return_revenue_amount
            FROM marketplace_costs c
            WHERE c.company_id  = :companyId
              AND c.marketplace = :marketplace
              AND c.cost_date  >= :periodFrom
              AND c.cost_date  <= :periodTo
            SQL,            [
            'companyId'   => $companyId,
            'marketplace' => $marketplace,
            'periodFrom'  => $periodFrom,
            'periodTo'    => $periodTo,
        ],
        );

        $apiNetAmount    = (float) ($apiStats['net_amount'] ?? 0);
        $returnRevenue   = (float) ($apiStats['return_revenue_amount'] ?? 0);
        $apiCostsAmount  = (float) ($apiStats['costs_amount'] ?? 0);
        $apiStornoAmount = (float) ($apiStats['storno_amount'] ?? 0);

        // xlsx_total = сумма отрицательных serviceGroup итогов
        // Считаем нетто по каждой группе, берём только отрицательные группы.
        // Это соответствует логике xlsx: расходные группы имеют отрицательный итог.
        // Доходные группы (Продажи, Компенсации с положительным итогом) исключаются автоматически.
        $groupTotals = [];
        foreach ($reportResult['lines'] as $line) {
            $group   = $line['serviceGroup'] ?: 'Без группы';
            $lineNet = $line['accruals']['total'] + $line['expenses']['total'] + $line['storno']['total'];
            $groupTotals[$group] = ($groupTotals[$group] ?? 0.0) + $lineNet;
        }

        $xlsxExpensesOnly = 0.0;
        foreach ($groupTotals as $groupNet) {
            if ($groupNet < 0) {
                $xlsxExpensesOnly += $groupNet;
            }
        }
        $xlsxTotal = abs($xlsxExpensesOnly);

        // Сопоставимая сумма — наша формула сверки
        $xlsxComparable = $apiNetAmount + $returnRevenue;

        $delta  = round(abs($xlsxComparable) - $xlsxTotal, 2);
        $status = abs($delta) < 0.01 ? 'matched' : 'mismatch';

        return [
            'status'            => $status,
            'api_net_amount'    => round($apiNetAmount, 2),
            'api_costs_amount'  => round($apiCostsAmount, 2),
            'api_storno_amount' => round($apiStornoAmount, 2),
            'return_revenue'    => round($returnRevenue, 2),
            'xlsx_comparable'   => round($xlsxComparable, 2),
            'xlsx_total'        => round($xlsxTotal, 2),
            'delta'             => $delta,
            'xlsx_period'       => $reportResult['period'] ?? '',
            'xlsx_lines_count'  => count($reportResult['lines'] ?? []),
        ];
    }
}

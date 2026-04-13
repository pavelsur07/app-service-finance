<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Application\Reconciliation\OzonXlsxServiceGroupMap;
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
        // net_amount / costs_amount / storno_amount классифицируются строго по operation_type.
        // После Phase 2B operation_type гарантированно NOT NULL для всех строк.
        // ABS() применяется безусловно: storno всегда имеет amount > 0, charge тоже —
        // net_amount = charges − stornos вычисляется через signed-ABS (raw SUM не подходит,
        // т.к. storno с amount > 0 не вычитался бы автоматически).
        $apiStats = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT
                SUM(CASE
                    WHEN (c.operation_type = 'storno')
                    THEN -ABS(c.amount)
                    ELSE ABS(c.amount)
                END)                                                        AS net_amount,
                SUM(CASE
                    WHEN (c.operation_type = 'storno')
                    THEN 0
                    ELSE ABS(c.amount)
                END)                                                        AS costs_amount,
                SUM(CASE
                    WHEN (c.operation_type = 'storno')
                    THEN ABS(c.amount)
                    ELSE 0
                END)                                                        AS storno_amount,
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

        // xlsx_total = сумма итогов отрицательных serviceGroup из xlsx.
        // Важно: считаем по groupTotals из сырых сумм amount, не через классификатор.
        // Классификатор (baseSign) может ошибаться когда один typeName встречается
        // в разных serviceGroup с разным смыслом (например 'Баллы за скидки' в Продажах и Возвратах).
        // reportResult содержит lines с groupTotals через accruals+expenses+storno —
        // это уже искажено классификатором. Поэтому используем отдельный ключ groupNetByServiceGroup
        // если он передан, иначе считаем по lines.
        $groupTotals = $reportResult['groupNetByServiceGroup'] ?? null;

        if ($groupTotals === null) {
            // Fallback: считаем по lines (менее точно)
            $groupTotals = [];
            foreach ($reportResult['lines'] as $line) {
                $group   = $line['serviceGroup'] ?: 'Без группы';
                $lineNet = $line['accruals']['total'] + $line['expenses']['total'] + $line['storno']['total'];
                $groupTotals[$group] = ($groupTotals[$group] ?? 0.0) + $lineNet;
            }
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

        // Сверка по serviceGroup — сравниваем xlsx группы с нашими категориями
        $groupComparison = $this->buildGroupComparison(
            $companyId, $marketplace, $periodFrom, $periodTo,
            $groupTotals,
        );

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
            'group_comparison'  => $groupComparison,
        ];
    }

    /**
     * Сверка по serviceGroup: xlsx vs наши категории.
     *
     * @param array<string, float> $xlsxGroupTotals serviceGroup → raw sum
     * @return array<int, array<string, mixed>>
     */
    private function buildGroupComparison(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
        array $xlsxGroupTotals,
    ): array {
        // Наши суммы по категориям.
        // net_amount / costs_amount / storno_amount классифицируются по operation_type —
        // та же логика что в reconcile() выше.
        $apiByCategory = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                cc.code                                                        AS category_code,
                SUM(CASE
                    WHEN (c.operation_type = 'storno')
                    THEN -ABS(c.amount)
                    ELSE ABS(c.amount)
                END)                                                           AS net_amount,
                SUM(CASE
                    WHEN (c.operation_type = 'storno')
                    THEN 0
                    ELSE ABS(c.amount)
                END)                                                           AS costs_amount,
                SUM(CASE
                    WHEN (c.operation_type = 'storno')
                    THEN ABS(c.amount)
                    ELSE 0
                END)                                                           AS storno_amount
            FROM marketplace_costs c
            INNER JOIN marketplace_cost_categories cc ON cc.id = c.category_id
            WHERE c.company_id  = :companyId
              AND c.marketplace = :marketplace
              AND c.cost_date  >= :periodFrom
              AND c.cost_date  <= :periodTo
            GROUP BY cc.code
            SQL,
            [
                'companyId'   => $companyId,
                'marketplace' => $marketplace,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
            ],
        );

        // Группируем наши категории по serviceGroup
        $categoryToGroup = OzonXlsxServiceGroupMap::getCategoryToServiceGroup();
        $apiByGroup      = [];

        foreach ($apiByCategory as $row) {
            $group = $categoryToGroup[$row['category_code']] ?? 'Не определена';
            if (!isset($apiByGroup[$group])) {
                $apiByGroup[$group] = ['net_amount' => 0.0, 'costs_amount' => 0.0, 'storno_amount' => 0.0];
            }
            $apiByGroup[$group]['net_amount']    += (float) $row['net_amount'];
            $apiByGroup[$group]['costs_amount']  += (float) $row['costs_amount'];
            $apiByGroup[$group]['storno_amount'] += (float) $row['storno_amount'];
        }

        // Объединяем xlsx и API по группам
        $allGroups = array_unique(array_merge(
            array_keys($xlsxGroupTotals),
            array_keys($apiByGroup),
        ));

        $result = [];
        foreach ($allGroups as $group) {
            $xlsxNet = $xlsxGroupTotals[$group] ?? 0.0;
            $apiNet  = $apiByGroup[$group]['net_amount'] ?? 0.0;

            // Для группы Возвраты api = return_revenue (из marketplace_returns)
            $delta  = round(abs($xlsxNet) - abs($apiNet), 2);
            $status = abs($delta) < 0.01 ? 'matched' : 'mismatch';

            $result[] = [
                'service_group'  => $group,
                'xlsx_net'       => round($xlsxNet, 2),
                'api_net'        => round($apiNet, 2),
                'api_costs'      => round($apiByGroup[$group]['costs_amount'] ?? 0.0, 2),
                'api_storno'     => round($apiByGroup[$group]['storno_amount'] ?? 0.0, 2),
                'delta'          => $delta,
                'status'         => $status,
            ];
        }

        // Сортируем: сначала mismatch, потом по имени группы
        usort($result, static function (array $a, array $b): int {
            if ($a['status'] !== $b['status']) {
                return $a['status'] === 'mismatch' ? -1 : 1;
            }
            return strcmp($a['service_group'], $b['service_group']);
        });

        return $result;
    }
}

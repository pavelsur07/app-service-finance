<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Сверка затрат Ozon за период с «Детализацией начислений» из ЛК.
 *
 * Как использовать:
 *   1. Открыть Ozon Seller → Финансы → Детализация начислений
 *   2. Скачать .xlsx за тот же период
 *   3. Сравнить totals_by_category[*].amount с суммами по service_name в xlsx
 *   4. Сравнить grand_total с итогом колонки «Сумма» в xlsx
 *
 * Признак проблемы:
 *   - unknown_service_names.count > 0 → есть новые service names у Ozon,
 *     нужно добавить в SERVICE_CATEGORY_MAP и переобработать затраты
 */
final class CostsVerifyQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
        ?float $xlsxTotal = null,
    ): array {
        $grandTotal          = $this->grandTotal($companyId, $marketplace, $periodFrom, $periodTo);
        $returnsReconciliation = $this->returnsReconciliation($companyId, $marketplace, $periodFrom, $periodTo);
        $unknownServiceNames = $this->unknownServiceNames($companyId, $marketplace, $periodFrom, $periodTo);
        $reconciliation      = $this->reconciliation($grandTotal, $returnsReconciliation, $xlsxTotal);
        $periodHealth        = $this->periodHealth($unknownServiceNames, $reconciliation);

        // Убираем внутренние _raw поля перед отдачей
        $grandTotalPublic = array_filter(
            $grandTotal,
            static fn (string $key) => !str_starts_with($key, '_'),
            ARRAY_FILTER_USE_KEY,
        );

        return [
            'period_health'          => $periodHealth,
            'reconciliation'         => $reconciliation,
            'raw_documents'          => $this->rawDocuments($companyId, $marketplace, $periodFrom, $periodTo),
            'totals_by_category'     => $this->totalsByCategory($companyId, $marketplace, $periodFrom, $periodTo),
            'grand_total'            => $grandTotalPublic,
            'returns_reconciliation' => $returnsReconciliation,
            'returns_detail'         => $this->returnsDetail($companyId, $marketplace, $periodFrom, $periodTo),
            'unknown_service_names'  => $unknownServiceNames,
            'coverage'               => $this->coverage($companyId, $marketplace, $periodFrom, $periodTo),
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * Итоги по каждой категории затрат за период.
     *
     * Сравни каждую строку с суммой по соответствующему service_name в xlsx Ozon.
     *
     * @return array<int, array<string, mixed>>
     */
    private function totalsByCategory(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                cc.code                          AS category_code,
                cc.name                          AS category_name,
                COUNT(c.id)                      AS count,
                SUM(c.amount)                    AS amount,
                COUNT(c.listing_id)              AS linked_to_sku,
                COUNT(c.id) - COUNT(c.listing_id) AS general_costs
            FROM marketplace_costs c
            INNER JOIN marketplace_cost_categories cc ON cc.id = c.category_id
            WHERE c.company_id  = :companyId
              AND c.marketplace = :marketplace
              AND c.cost_date  >= :periodFrom
              AND c.cost_date  <= :periodTo
            GROUP BY cc.code, cc.name
            ORDER BY SUM(c.amount) DESC
            SQL,
            [
                'companyId'   => $companyId,
                'marketplace' => $marketplace,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
            ],
        );

        return array_map(static fn (array $r) => [
            'category_code'  => $r['category_code'],
            'category_name'  => $r['category_name'],
            'count'          => (int) $r['count'],
            'amount'         => number_format((float) $r['amount'], 2, '.', ' '),
            'linked_to_sku'  => (int) $r['linked_to_sku'],
            'general_costs'  => (int) $r['general_costs'],
        ], $rows);
    }

    /**
     * Итоговая сумма всех затрат за период.
     *
     * Знаковое соглашение:
     *   amount > 0 — затрата (расход продавца)
     *   amount < 0 — сторно/возврат от маркетплейса (уменьшение затрат)
     *
     * costs_amount  = сумма всех затрат (amount > 0)
     * storno_amount = сумма всех сторно ABS(amount < 0)
     * net_amount    = costs_amount - storno_amount = grand_total
     *
     * xlsx_comparable = net_amount + return_revenue_amount
     *   Это число нужно сравнивать с итогом xlsx «Детализации начислений».
     *   Разница только в возвратах выручки покупателям — Ozon включает их в xlsx,
     *   мы учитываем в marketplace_returns отдельно.
     */
    private function grandTotal(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT
                COUNT(c.id)                                            AS total_count,
                SUM(c.amount)                                          AS net_amount,
                SUM(CASE WHEN c.amount > 0 THEN c.amount  ELSE 0 END) AS costs_amount,
                SUM(CASE WHEN c.amount < 0 THEN ABS(c.amount) ELSE 0 END) AS storno_amount,
                COUNT(c.listing_id)                                    AS linked_to_sku,
                COUNT(c.id) - COUNT(c.listing_id)                      AS general_costs
            FROM marketplace_costs c
            WHERE c.company_id  = :companyId
              AND c.marketplace = :marketplace
              AND c.cost_date  >= :periodFrom
              AND c.cost_date  <= :periodTo
            SQL,
            [
                'companyId'   => $companyId,
                'marketplace' => $marketplace,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
            ],
        );

        $netAmount    = (float) ($row['net_amount'] ?? 0);
        $costsAmount  = (float) ($row['costs_amount'] ?? 0);
        $stornoAmount = (float) ($row['storno_amount'] ?? 0);

        // return_revenue_amount берём из returnsReconciliation — здесь вычисляем отдельно
        // для xlsx_comparable (чтобы не делать второй запрос передаём null, заполняется в reconciliation())
        return [
            'hint'          => 'net_amount = costs_amount − storno_amount. xlsx_comparable = net_amount + return_revenue_amount — сравни с итогом xlsx.',
            'total_count'   => (int) ($row['total_count'] ?? 0),
            'costs_amount'  => number_format($costsAmount, 2, '.', ' '),
            'storno_amount' => number_format($stornoAmount, 2, '.', ' '),
            'net_amount'    => number_format($netAmount, 2, '.', ' '),
            'linked_to_sku' => (int) ($row['linked_to_sku'] ?? 0),
            'general_costs' => (int) ($row['general_costs'] ?? 0),
            // _raw используется внутри для reconciliation(), не выводится напрямую
            '_net_amount_raw'    => $netAmount,
            '_costs_amount_raw'  => $costsAmount,
            '_storno_amount_raw' => $stornoAmount,
        ];
    }

    /**
     * Затраты в категории 'ozon_other_service' с description = неизвестный service name.
     *
     * Если count > 0 — у Ozon появились новые service names которых нет в SERVICE_CATEGORY_MAP.
     * Нужно:
     *   1. Добавить service name в ProcessOzonCostsAction::SERVICE_CATEGORY_MAP
     *   2. Переобработать затраты через «Переобработка данных за период»
     */
    private function unknownServiceNames(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                c.description           AS service_name,
                COUNT(c.id)             AS count,
                SUM(c.amount)           AS amount,
                MIN(c.cost_date)::text  AS first_date,
                MAX(c.cost_date)::text  AS last_date
            FROM marketplace_costs c
            INNER JOIN marketplace_cost_categories cc ON cc.id = c.category_id
            WHERE c.company_id  = :companyId
              AND c.marketplace = :marketplace
              AND c.cost_date  >= :periodFrom
              AND c.cost_date  <= :periodTo
              AND cc.code       = 'ozon_other_service'
            GROUP BY c.description
            ORDER BY SUM(c.amount) DESC
            SQL,
            [
                'companyId'   => $companyId,
                'marketplace' => $marketplace,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
            ],
        );

        $total = array_sum(array_column($rows, 'count'));

        return [
            'hint'   => 'count > 0 означает новые service names от Ozon — добавь в SERVICE_CATEGORY_MAP и переобработай',
            'count'  => (int) $total,
            'status' => $total === 0
                ? 'OK — все service names распознаны'
                : 'WARNING — есть нераспознанные service names, смотри items',
            'items'  => array_map(static fn (array $r) => [
                'service_name' => $r['service_name'],
                'count'        => (int) $r['count'],
                'amount'       => number_format((float) $r['amount'], 2, '.', ' '),
                'first_date'   => $r['first_date'],
                'last_date'    => $r['last_date'],
            ], $rows),
        ];
    }

    /**
     * Покрытие — сколько затрат привязано к SKU vs общие (без SKU).
     * Помогает понять полноту данных.
     */
    private function coverage(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT
                COUNT(c.id)                               AS total,
                COUNT(c.listing_id)                       AS with_sku,
                COUNT(c.id) - COUNT(c.listing_id)         AS without_sku,
                SUM(c.amount)                             AS total_amount,
                SUM(CASE WHEN c.listing_id IS NOT NULL
                    THEN c.amount ELSE 0 END)             AS amount_with_sku,
                SUM(CASE WHEN c.listing_id IS NULL
                    THEN c.amount ELSE 0 END)             AS amount_without_sku
            FROM marketplace_costs c
            WHERE c.company_id  = :companyId
              AND c.marketplace = :marketplace
              AND c.cost_date  >= :periodFrom
              AND c.cost_date  <= :periodTo
            SQL,
            [
                'companyId'   => $companyId,
                'marketplace' => $marketplace,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
            ],
        );

        $total = (int) ($row['total'] ?? 0);

        return [
            'hint'              => 'Затраты без SKU (общие) — хранение, реклама и т.д. Затраты с SKU — логистика, упаковка, эквайринг конкретного товара',
            'total'             => $total,
            'with_sku'          => (int) ($row['with_sku'] ?? 0),
            'without_sku'       => (int) ($row['without_sku'] ?? 0),
            'amount_with_sku'   => number_format((float) ($row['amount_with_sku'] ?? 0), 2, '.', ' '),
            'amount_without_sku'=> number_format((float) ($row['amount_without_sku'] ?? 0), 2, '.', ' '),
        ];
    }

    /**
     * Raw-документы за период — для проверки полноты переобработки.
     * Если какой-то документ не переобработан — затраты за его период отсутствуют.
     */
    private function rawDocuments(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                id,
                document_type,
                period_from::text  AS period_from,
                period_to::text    AS period_to,
                records_count,
                synced_at::text    AS synced_at
            FROM marketplace_raw_documents
            WHERE company_id    = :companyId
              AND marketplace   = :marketplace
              AND document_type = 'sales_report'
              AND period_from  >= :periodFrom
              AND period_to    <= :periodTo
            ORDER BY period_from
            SQL,
            [
                'companyId'   => $companyId,
                'marketplace' => $marketplace,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
            ],
        );

        return [
            'hint'  => 'Все документы должны быть переобработаны. Если список неполный — загрузи недостающий период через «Синхронизировать за период»',
            'count' => count($rows),
            'items' => array_map(static fn (array $r) => [
                'id'            => $r['id'],
                'period'        => $r['period_from'] . ' – ' . $r['period_to'],
                'records_count' => (int) $r['records_count'],
                'synced_at'     => $r['synced_at'],
            ], $rows),
        ];
    }


    /**
     * Детализация возвратов за период.
     *
     * Показывает полную картину по возвратам:
     *   - Сколько возвратов покупателей (ClientReturnAgentOperation)
     *   - Сумма возвращённой выручки покупателям (accruals_for_sale < 0)
     *   - Сумма возвращённой комиссии продавцу (sale_commission > 0) — уже учтена в затратах как -комиссия
     *   - Итоговый «чистый» эффект на затраты
     *
     * В xlsx Ozon возвращённая выручка входит в группу «Возвраты» → «Возврат выручки»
     * и увеличивает итоговую сумму затрат в отчёте.
     * У нас это учтено в marketplace_returns, не в marketplace_costs.
     *
     * Формула сверки:
     *   xlsx_grand_total = наш_grand_total + return_revenue_amount - commission_returned_amount
     */
    private function returnsDetail(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        // Возвращённая комиссия, которую мы УЖЕ пишем в marketplace_costs как отрицательную затрату
        $commissionReturned = (float) ($this->connection->fetchOne(
            <<<'SQL'
            SELECT COALESCE(SUM(ABS(c.amount)), 0)
            FROM marketplace_costs c
            INNER JOIN marketplace_cost_categories cc ON cc.id = c.category_id
            WHERE c.company_id  = :companyId
              AND c.marketplace = :marketplace
              AND c.cost_date  >= :periodFrom
              AND c.cost_date  <= :periodTo
              AND cc.code       = 'ozon_sale_commission'
              AND c.amount      < 0
            SQL,
            [
                'companyId'   => $companyId,
                'marketplace' => $marketplace,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
            ],
        ) ?? 0);

        // Возвраты из marketplace_returns — выручка возвращённая покупателям
        $returnsRow = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT
                COUNT(*)                                            AS total_count,
                COALESCE(SUM(refund_amount), 0)                    AS total_refund_amount,
                COALESCE(SUM(quantity), 0)                         AS total_quantity,
                COUNT(*) FILTER (WHERE sale_id IS NOT NULL)        AS matched_to_sale,
                COUNT(*) FILTER (WHERE sale_id IS NULL)            AS unmatched
            FROM marketplace_returns
            WHERE company_id   = :companyId
              AND marketplace  = :marketplace
              AND return_date >= :periodFrom
              AND return_date <= :periodTo
            SQL,
            [
                'companyId'   => $companyId,
                'marketplace' => $marketplace,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
            ],
        );

        $totalRefund = (float) ($returnsRow['total_refund_amount'] ?? 0);

        // Разбивка по причине возврата
        $byReason = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                COALESCE(return_reason, 'не указана') AS return_reason,
                COUNT(*)                              AS count,
                COALESCE(SUM(quantity), 0)            AS quantity,
                SUM(refund_amount)                    AS refund_amount
            FROM marketplace_returns
            WHERE company_id   = :companyId
              AND marketplace  = :marketplace
              AND return_date >= :periodFrom
              AND return_date <= :periodTo
            GROUP BY return_reason
            ORDER BY SUM(refund_amount) DESC
            SQL,
            [
                'companyId'   => $companyId,
                'marketplace' => $marketplace,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
            ],
        );

        // Разбивка по дням
        $byDay = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                return_date::text       AS return_date,
                COUNT(*)                AS count,
                SUM(quantity)           AS quantity,
                SUM(refund_amount)      AS refund_amount
            FROM marketplace_returns
            WHERE company_id   = :companyId
              AND marketplace  = :marketplace
              AND return_date >= :periodFrom
              AND return_date <= :periodTo
            GROUP BY return_date
            ORDER BY return_date
            SQL,
            [
                'companyId'   => $companyId,
                'marketplace' => $marketplace,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
            ],
        );

        return [
            'hint' => implode(' ', [
                'Детализация возвратов за период.',
                'return_revenue_amount — выручка возвращённая покупателям (учтена в marketplace_returns, НЕ в затратах).',
                'commission_returned_amount — возврат комиссии продавцу (уже учтён в затратах как отрицательная комиссия).',
                'xlsx_reconciliation_delta — разница которую ты увидишь между нашим grand_total и xlsx.',
            ]),
            'total_returns'              => (int) ($returnsRow['total_count'] ?? 0),
            'total_quantity'             => (int) ($returnsRow['total_quantity'] ?? 0),
            'matched_to_sale'            => (int) ($returnsRow['matched_to_sale'] ?? 0),
            'unmatched'                  => (int) ($returnsRow['unmatched'] ?? 0),
            'return_revenue_amount'      => number_format($totalRefund, 2, '.', ' '),
            'commission_returned_amount' => number_format($commissionReturned, 2, '.', ' '),
            'xlsx_reconciliation_delta'  => number_format($totalRefund, 2, '.', ' '),
            'note' => sprintf(
                'xlsx покажет на ~%s больше чем наш grand_total — это возвраты выручки покупателям (группа «Возвраты» → «Возврат выручки» в xlsx)',
                number_format($totalRefund, 2, '.', ' '),
            ),
            'by_reason' => array_map(static fn (array $r) => [
                'reason'        => $r['return_reason'],
                'count'         => (int) $r['count'],
                'quantity'      => (int) $r['quantity'],
                'refund_amount' => number_format((float) $r['refund_amount'], 2, '.', ' '),
            ], $byReason),
            'by_day' => array_map(static fn (array $r) => [
                'date'          => $r['return_date'],
                'count'         => (int) $r['count'],
                'quantity'      => (int) $r['quantity'],
                'refund_amount' => number_format((float) $r['refund_amount'], 2, '.', ' '),
            ], $byDay),
        ];
    }

    /**
     * Сверка возвратов: проверяем что сумма возвратов из marketplace_returns
     * соответствует разнице grand_total между нашим расчётом и xlsx.
     *
     * Разница = refund_amount из marketplace_returns (уже учтён отдельно).
     * Если returns_total совпадает с расхождением — всё корректно.
     */
    private function returnsReconciliation(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT
                COUNT(*)                    AS total_returns,
                COALESCE(SUM(refund_amount), 0) AS total_refund_amount,
                COUNT(*) FILTER (WHERE document_id IS NOT NULL) AS closed_returns,
                COUNT(*) FILTER (WHERE document_id IS NULL)     AS open_returns
            FROM marketplace_returns
            WHERE company_id   = :companyId
              AND marketplace  = :marketplace
              AND return_date >= :periodFrom
              AND return_date <= :periodTo
            SQL,
            [
                'companyId'   => $companyId,
                'marketplace' => $marketplace,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
            ],
        );

        $totalRefund = (float) ($row['total_refund_amount'] ?? 0);

        return [
            'hint'                => 'Сумма возвратов из marketplace_returns. Разница grand_total с xlsx = эта сумма (возвраты учтены отдельно, не в затратах)',
            'total_returns'       => (int) ($row['total_returns'] ?? 0),
            'total_refund_amount' => number_format($totalRefund, 2, '.', ' '),
            'closed_returns'      => (int) ($row['closed_returns'] ?? 0),
            'open_returns'        => (int) ($row['open_returns'] ?? 0),
            'status'              => $totalRefund > 0
                ? 'OK — возвраты учтены в marketplace_returns, не дублируются в затратах'
                : 'WARNING — нет данных о возвратах за период',
        ];
    }

    /**
     * Сверка с xlsx.
     *
     * Формула:
     *   xlsx_comparable = net_amount + return_revenue_amount
     *
     * Где:
     *   net_amount            — наши затраты нетто (costs − storno)
     *   return_revenue_amount — выручка возвращённая покупателям (в marketplace_returns, не в costs)
     *
     * Если передан xlsx_total — считает дельту и возвращает статус.
     * Допустимое отклонение: 0.00 (любая разница = MISMATCH).
     *
     * Знаковое соглашение MarketplaceCost.amount:
     *   > 0 — затрата (расход продавца, например комиссия, логистика)
     *   < 0 — сторно/возврат от маркетплейса (уменьшение затрат, например возврат комиссии)
     */
    private function reconciliation(
        array $grandTotal,
        array $returnsReconciliation,
        ?float $xlsxTotal,
    ): array {
        $netAmount           = $grandTotal['_net_amount_raw'];
        $returnRevenueAmount = (float) str_replace(' ', '', $returnsReconciliation['total_refund_amount']);
        $xlsxComparable      = $netAmount + $returnRevenueAmount;

        $result = [
            'hint'                 => 'xlsx_comparable = net_amount + return_revenue_amount. Передай ?xlsx_total=XXXXX для автоматической проверки.',
            'net_amount'           => number_format($netAmount, 2, '.', ' '),
            'return_revenue_amount'=> number_format($returnRevenueAmount, 2, '.', ' '),
            'xlsx_comparable'      => number_format($xlsxComparable, 2, '.', ' '),
        ];

        if ($xlsxTotal !== null) {
            $delta  = round($xlsxComparable - $xlsxTotal, 2);
            $status = $delta === 0.0 ? 'OK' : 'MISMATCH';

            $result['xlsx_total_provided'] = number_format($xlsxTotal, 2, '.', ' ');
            $result['delta']               = number_format($delta, 2, '.', ' ');
            $result['status']              = $status;

            if ($status === 'MISMATCH') {
                $result['hint_mismatch'] = $delta > 0
                    ? 'xlsx_comparable больше xlsx_total — возможно не все затраты обработаны или есть дублирование'
                    : 'xlsx_comparable меньше xlsx_total — возможно пропущены затраты или неверный маппинг';
            }
        }

        return $result;
    }

    /**
     * Агрегированный статус периода — один взгляд чтобы понять закрыт период или нет.
     *
     * OK       — все проверки прошли
     * WARNING  — отклонения требующие внимания но не критичные
     * MISMATCH — расхождение с xlsx (если xlsx_total передан)
     * ERROR    — нераспознанные service names или другие критичные проблемы
     */
    private function periodHealth(
        array $unknownServiceNames,
        array $reconciliation,
    ): array {
        $checks = [];

        // Проверка: нераспознанные service names
        $unknownCount = (int) ($unknownServiceNames['count'] ?? 0);
        $checks['unknown_service_names'] = $unknownCount === 0
            ? ['status' => 'OK',    'message' => 'Все service names распознаны']
            : ['status' => 'ERROR', 'message' => "Нераспознанных service names: {$unknownCount} — добавь в OzonServiceCategoryMap и переобработай"];

        // Проверка: сверка с xlsx (только если xlsx_total передан)
        if (isset($reconciliation['status'])) {
            $checks['reconciliation'] = $reconciliation['status'] === 'OK'
                ? ['status' => 'OK',       'message' => "Совпадает с xlsx: {$reconciliation['xlsx_comparable']}"]
                : ['status' => 'MISMATCH', 'message' => "Расхождение с xlsx: delta = {$reconciliation['delta']}"];
        } else {
            $checks['reconciliation'] = [
                'status'  => 'SKIPPED',
                'message' => 'Передай ?xlsx_total=XXXXX для проверки сверки с xlsx',
            ];
        }

        // Итоговый статус — worst case
        $statuses      = array_column($checks, 'status');
        $overallStatus = 'OK';
        if (in_array('ERROR', $statuses, true))        $overallStatus = 'ERROR';
        elseif (in_array('MISMATCH', $statuses, true)) $overallStatus = 'MISMATCH';
        elseif (in_array('WARNING', $statuses, true))  $overallStatus = 'WARNING';

        return [
            'status' => $overallStatus,
            'checks' => $checks,
        ];
    }

}

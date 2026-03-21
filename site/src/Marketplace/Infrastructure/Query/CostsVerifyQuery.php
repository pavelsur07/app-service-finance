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
    ): array {
        return [
            'totals_by_category'   => $this->totalsByCategory($companyId, $marketplace, $periodFrom, $periodTo),
            'grand_total'          => $this->grandTotal($companyId, $marketplace, $periodFrom, $periodTo),
            'unknown_service_names'=> $this->unknownServiceNames($companyId, $marketplace, $periodFrom, $periodTo),
            'coverage'             => $this->coverage($companyId, $marketplace, $periodFrom, $periodTo),
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
     * Сравни с итогом колонки «Сумма» в xlsx Ozon (только отрицательные строки = расходы).
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
                COUNT(c.id)   AS total_count,
                SUM(c.amount) AS total_amount,
                COUNT(c.listing_id)              AS linked_to_sku,
                COUNT(c.id) - COUNT(c.listing_id) AS general_costs
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

        return [
            'hint'          => 'Сравни total_amount с итогом колонки «Сумма» (расходы) в xlsx Детализации Ozon за тот же период',
            'total_count'   => (int) ($row['total_count'] ?? 0),
            'total_amount'  => number_format((float) ($row['total_amount'] ?? 0), 2, '.', ' '),
            'linked_to_sku' => (int) ($row['linked_to_sku'] ?? 0),
            'general_costs' => (int) ($row['general_costs'] ?? 0),
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
                c.description        AS service_name,
                COUNT(c.id)          AS count,
                SUM(c.amount)        AS amount
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
}

<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Анализ operation_type и service_name из raw-документов за период.
 *
 * Используется для диагностики расхождений между нашим grand_total и xlsx Ozon.
 * Показывает какие operation_type / service_name реально присутствуют в данных,
 * и в какую категорию они замаппированы (или не замаппированы).
 *
 * Использование:
 *   GET /marketplace/costs/debug/raw-operations?marketplace=ozon&year=2026&month=1
 *   GET /marketplace/costs/debug/raw-operations?marketplace=ozon&year=2026&month=1&category=ozon_crossdocking
 */
final class RawOperationsAnalysisQuery
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
        ?string $filterCategory = null,
    ): array {
        return [
            'operation_types' => $this->operationTypes($companyId, $marketplace, $periodFrom, $periodTo, $filterCategory),
            'service_names'   => $this->serviceNames($companyId, $marketplace, $periodFrom, $periodTo, $filterCategory),
            'category_totals' => $this->categoryTotals($companyId, $marketplace, $periodFrom, $periodTo, $filterCategory),
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * Все уникальные operation_type из затрат за период с суммами.
     * Показывает реальное распределение operation_type → category_code.
     *
     * @return array<int, array<string, mixed>>
     */
    private function operationTypes(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
        ?string $filterCategory,
    ): array {
        $categoryJoin = $filterCategory !== null
            ? "AND cc.code = :filterCategory"
            : "";

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT
                cc.code                     AS category_code,
                cc.name                     AS category_name,
                c.description               AS operation_type,
                COUNT(c.id)                 AS count,
                SUM(c.amount)               AS amount,
                MIN(c.cost_date)::text       AS first_date,
                MAX(c.cost_date)::text       AS last_date
            FROM marketplace_costs c
            INNER JOIN marketplace_cost_categories cc ON cc.id = c.category_id
            WHERE c.company_id   = :companyId
              AND c.marketplace  = :marketplace
              AND c.cost_date   >= :periodFrom
              AND c.cost_date   <= :periodTo
              AND c.listing_id  IS NULL
              {$categoryJoin}
            GROUP BY cc.code, cc.name, c.description
            ORDER BY cc.code, SUM(c.amount) DESC
            SQL,
            array_filter([
                'companyId'      => $companyId,
                'marketplace'    => $marketplace,
                'periodFrom'     => $periodFrom,
                'periodTo'       => $periodTo,
                'filterCategory' => $filterCategory,
            ]),
        );

        return array_map(static fn (array $r) => [
            'category_code'  => $r['category_code'],
            'category_name'  => $r['category_name'],
            'operation_type' => $r['operation_type'],
            'count'          => (int) $r['count'],
            'amount'         => number_format((float) $r['amount'], 2, '.', ' '),
            'first_date'     => $r['first_date'],
            'last_date'      => $r['last_date'],
        ], $rows);
    }

    /**
     * Все уникальные service_name (description) из затрат привязанных к SKU.
     * Показывает что реально пришло в services[] по операциям.
     *
     * @return array<int, array<string, mixed>>
     */
    private function serviceNames(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
        ?string $filterCategory,
    ): array {
        $categoryJoin = $filterCategory !== null
            ? "AND cc.code = :filterCategory"
            : "";

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT
                cc.code                     AS category_code,
                cc.name                     AS category_name,
                c.description               AS service_name,
                COUNT(c.id)                 AS count,
                SUM(c.amount)               AS amount,
                MIN(c.cost_date)::text       AS first_date,
                MAX(c.cost_date)::text       AS last_date
            FROM marketplace_costs c
            INNER JOIN marketplace_cost_categories cc ON cc.id = c.category_id
            WHERE c.company_id   = :companyId
              AND c.marketplace  = :marketplace
              AND c.cost_date   >= :periodFrom
              AND c.cost_date   <= :periodTo
              AND c.listing_id  IS NOT NULL
              {$categoryJoin}
            GROUP BY cc.code, cc.name, c.description
            ORDER BY cc.code, SUM(c.amount) DESC
            SQL,
            array_filter([
                'companyId'      => $companyId,
                'marketplace'    => $marketplace,
                'periodFrom'     => $periodFrom,
                'periodTo'       => $periodTo,
                'filterCategory' => $filterCategory,
            ]),
        );

        return array_map(static fn (array $r) => [
            'category_code' => $r['category_code'],
            'category_name' => $r['category_name'],
            'service_name'  => $r['service_name'],
            'count'         => (int) $r['count'],
            'amount'        => number_format((float) $r['amount'], 2, '.', ' '),
            'first_date'    => $r['first_date'],
            'last_date'     => $r['last_date'],
        ], $rows);
    }

    /**
     * Итоги по категориям — быстрый срез для сравнения с xlsx.
     *
     * @return array<int, array<string, mixed>>
     */
    private function categoryTotals(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
        ?string $filterCategory,
    ): array {
        $categoryJoin = $filterCategory !== null
            ? "AND cc.code = :filterCategory"
            : "";

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT
                cc.code         AS category_code,
                cc.name         AS category_name,
                COUNT(c.id)     AS count,
                SUM(c.amount)   AS amount
            FROM marketplace_costs c
            INNER JOIN marketplace_cost_categories cc ON cc.id = c.category_id
            WHERE c.company_id   = :companyId
              AND c.marketplace  = :marketplace
              AND c.cost_date   >= :periodFrom
              AND c.cost_date   <= :periodTo
              {$categoryJoin}
            GROUP BY cc.code, cc.name
            ORDER BY SUM(c.amount) DESC
            SQL,
            array_filter([
                'companyId'      => $companyId,
                'marketplace'    => $marketplace,
                'periodFrom'     => $periodFrom,
                'periodTo'       => $periodTo,
                'filterCategory' => $filterCategory,
            ]),
        );

        return array_map(static fn (array $r) => [
            'category_code' => $r['category_code'],
            'category_name' => $r['category_name'],
            'count'         => (int) $r['count'],
            'amount'        => number_format((float) $r['amount'], 2, '.', ' '),
        ], $rows);
    }
}

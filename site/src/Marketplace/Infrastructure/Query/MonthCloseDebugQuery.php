<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Debug-запросы для страницы сверки «Закрытие месяца».
 *
 * Три уровня детализации:
 *   1. aggregateSalesReturns() — итого по AmountSource → PLCategory
 *   2. aggregateRealization()  — итого по Ozon realization → PLCategory
 *   3. detail*()               — постраничный список операций
 *
 * Фильтры статуса:
 *   processed=null  → все записи
 *   processed=false → document_id IS NULL  (ещё не закрыты)
 *   processed=true  → document_id IS NOT NULL (уже закрыты)
 *   documentId      → только записи конкретного PLDocument
 */
final class MonthCloseDebugQuery
{
    private const PAGE_SIZE = 50;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    // -------------------------------------------------------------------------
    // Агрегат: продажи и возвраты
    // -------------------------------------------------------------------------

    /**
     * @return array<int, array{
     *     source_type:         string,
     *     amount_source:       string,
     *     pl_category_id:      string,
     *     pl_category_name:    string,
     *     is_negative:         bool,
     *     records_count:       int,
     *     total_amount:        string,
     *     document_ids:        string[],
     * }>
     */
    public function aggregateSalesReturns(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
        ?bool $processed,
        ?string $documentId,
    ): array {
        return array_merge(
            $this->aggregateTable('marketplace_sales',  'sale_date',   'sale',   $companyId, $marketplace, $periodFrom, $periodTo, $processed, $documentId),
            $this->aggregateTable('marketplace_returns', 'return_date', 'return', $companyId, $marketplace, $periodFrom, $periodTo, $processed, $documentId),
        );
    }

    // -------------------------------------------------------------------------
    // Агрегат: реализация Ozon
    // -------------------------------------------------------------------------

    /**
     * @return array<int, array{
     *     pl_category_id:           string,
     *     pl_category_name:         string,
     *     is_negative:              bool,
     *     records_count:            int,
     *     total_seller_price_amount: string,
     *     total_amount:             string,
     *     document_ids:             string[],
     * }>
     */
    public function aggregateRealization(
        string $companyId,
        string $periodFrom,
        string $periodTo,
        ?bool $processed,
        ?string $documentId,
    ): array {
        $params = [
            'companyId'  => $companyId,
            'periodFrom' => $periodFrom,
            'periodTo'   => $periodTo,
        ];

        $filter = $this->buildProcessedFilter('r.pl_document_id', $processed, $documentId, $params);

        $sql = <<<SQL
            SELECT
                m.pl_category_id,
                cat.name                                        AS pl_category_name,
                m.is_negative,
                COUNT(r.id)                                     AS records_count,
                SUM(r.seller_price_per_instance * r.quantity)  AS total_seller_price_amount,
                SUM(r.total_amount)                             AS total_amount,
                array_agg(DISTINCT r.pl_document_id)
                    FILTER (WHERE r.pl_document_id IS NOT NULL) AS document_ids
            FROM marketplace_ozon_realizations r
            INNER JOIN marketplace_sale_mappings m
                ON m.company_id = r.company_id
               AND m.marketplace = 'ozon'
               AND m.amount_source = 'sale_realization'
               AND m.is_active = true
            INNER JOIN pl_categories cat ON cat.id = m.pl_category_id
            WHERE r.company_id = :companyId
              AND r.period_from >= :periodFrom
              AND r.period_to   <= :periodTo
              {$filter}
            GROUP BY m.pl_category_id, cat.name, m.is_negative
            ORDER BY cat.name ASC
        SQL;

        return array_map(
            fn (array $row) => $this->normalizeDocumentIds($row),
            $this->connection->fetchAllAssociative($sql, $params),
        );
    }

    // -------------------------------------------------------------------------
    // Детализация: продажи
    // -------------------------------------------------------------------------

    /**
     * @return array{rows: list<array<string,mixed>>, total: int, page: int, pages: int}
     */
    public function detailSales(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
        ?bool $processed,
        ?string $documentId,
        int $page = 1,
    ): array {
        $params = [
            'companyId'   => $companyId,
            'marketplace' => $marketplace,
            'periodFrom'  => $periodFrom,
            'periodTo'    => $periodTo,
        ];

        $filter = $this->buildProcessedFilter('s.document_id', $processed, $documentId, $params);

        $total = (int) $this->connection->fetchOne(
            "SELECT COUNT(DISTINCT s.id)
             FROM marketplace_sales s
             INNER JOIN marketplace_sale_mappings m
                 ON m.company_id = s.company_id
                AND m.marketplace = s.marketplace
                AND m.operation_type = 'sale'
                AND m.is_active = true
             WHERE s.company_id = :companyId
               AND s.marketplace = :marketplace
               AND s.sale_date >= :periodFrom
               AND s.sale_date <= :periodTo
               {$filter}",
            $params,
        );

        $offset = ($page - 1) * self::PAGE_SIZE;
        $params['limit']  = self::PAGE_SIZE;
        $params['offset'] = $offset;

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT
                s.id,
                s.external_order_id,
                s.sale_date::date::text     AS sale_date,
                l.marketplace_sku,
                l.name                      AS listing_name,
                s.quantity,
                s.price_per_unit,
                s.total_revenue,
                s.cost_price,
                m.amount_source,
                m.is_negative,
                m.pl_category_id,
                cat.name                    AS pl_category_name,
                CASE m.amount_source
                    WHEN 'sale_gross'      THEN (s.price_per_unit * s.quantity)::numeric(15,2)
                    WHEN 'sale_revenue'    THEN s.total_revenue::numeric(15,2)
                    WHEN 'sale_cost_price' THEN (COALESCE(s.cost_price, 0) * s.quantity)::numeric(15,2)
                    ELSE 0
                END                         AS computed_amount,
                s.document_id
            FROM marketplace_sales s
            LEFT  JOIN marketplace_listings l ON l.id = s.listing_id
            INNER JOIN marketplace_sale_mappings m
                ON m.company_id = s.company_id
               AND m.marketplace = s.marketplace
               AND m.operation_type = 'sale'
               AND m.is_active = true
            INNER JOIN pl_categories cat ON cat.id = m.pl_category_id
            WHERE s.company_id = :companyId
              AND s.marketplace = :marketplace
              AND s.sale_date >= :periodFrom
              AND s.sale_date <= :periodTo
              {$filter}
            ORDER BY s.sale_date DESC, s.external_order_id
            LIMIT :limit OFFSET :offset
            SQL,
            $params,
        );

        return $this->paginate($rows, $total, $page);
    }

    // -------------------------------------------------------------------------
    // Детализация: возвраты
    // -------------------------------------------------------------------------

    /**
     * @return array{rows: list<array<string,mixed>>, total: int, page: int, pages: int}
     */
    public function detailReturns(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
        ?bool $processed,
        ?string $documentId,
        int $page = 1,
    ): array {
        $params = [
            'companyId'   => $companyId,
            'marketplace' => $marketplace,
            'periodFrom'  => $periodFrom,
            'periodTo'    => $periodTo,
        ];

        $filter = $this->buildProcessedFilter('r.document_id', $processed, $documentId, $params);

        $total = (int) $this->connection->fetchOne(
            "SELECT COUNT(DISTINCT r.id)
             FROM marketplace_returns r
             INNER JOIN marketplace_sale_mappings m
                 ON m.company_id = r.company_id
                AND m.marketplace = r.marketplace
                AND m.operation_type = 'return'
                AND m.is_active = true
             WHERE r.company_id = :companyId
               AND r.marketplace = :marketplace
               AND r.return_date >= :periodFrom
               AND r.return_date <= :periodTo
               {$filter}",
            $params,
        );

        $offset = ($page - 1) * self::PAGE_SIZE;
        $params['limit']  = self::PAGE_SIZE;
        $params['offset'] = $offset;

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT
                r.id,
                r.external_return_id,
                r.return_date::date::text   AS return_date,
                l.marketplace_sku,
                l.name                      AS listing_name,
                r.quantity,
                r.refund_amount,
                ms.price_per_unit           AS sale_price_per_unit,
                ms.cost_price               AS sale_cost_price,
                m.amount_source,
                m.is_negative,
                m.pl_category_id,
                cat.name                    AS pl_category_name,
                CASE m.amount_source
                    WHEN 'return_refund'
                        THEN COALESCE(r.refund_amount, 0)::numeric(15,2)
                    WHEN 'return_gross'
                        THEN COALESCE(ms.price_per_unit * r.quantity, r.refund_amount, 0)::numeric(15,2)
                    WHEN 'return_cost_price'
                        THEN COALESCE(ms.cost_price * r.quantity, 0)::numeric(15,2)
                    ELSE 0
                END                         AS computed_amount,
                r.document_id
            FROM marketplace_returns r
            LEFT  JOIN marketplace_listings l  ON l.id  = r.listing_id
            LEFT  JOIN marketplace_sales    ms ON ms.id = r.sale_id
            INNER JOIN marketplace_sale_mappings m
                ON m.company_id = r.company_id
               AND m.marketplace = r.marketplace
               AND m.operation_type = 'return'
               AND m.is_active = true
            INNER JOIN pl_categories cat ON cat.id = m.pl_category_id
            WHERE r.company_id = :companyId
              AND r.marketplace = :marketplace
              AND r.return_date >= :periodFrom
              AND r.return_date <= :periodTo
              {$filter}
            ORDER BY r.return_date DESC, r.external_return_id
            LIMIT :limit OFFSET :offset
            SQL,
            $params,
        );

        return $this->paginate($rows, $total, $page);
    }

    // -------------------------------------------------------------------------
    // Детализация: реализация Ozon
    // -------------------------------------------------------------------------

    /**
     * @return array{rows: list<array<string,mixed>>, total: int, page: int, pages: int}
     */
    public function detailRealization(
        string $companyId,
        string $periodFrom,
        string $periodTo,
        ?bool $processed,
        ?string $documentId,
        int $page = 1,
    ): array {
        $params = [
            'companyId'  => $companyId,
            'periodFrom' => $periodFrom,
            'periodTo'   => $periodTo,
        ];

        $filter = $this->buildProcessedFilter('r.pl_document_id', $processed, $documentId, $params);

        $total = (int) $this->connection->fetchOne(
            "SELECT COUNT(*)
             FROM marketplace_ozon_realizations r
             INNER JOIN marketplace_sale_mappings m
                 ON m.company_id = r.company_id
                AND m.marketplace = 'ozon'
                AND m.amount_source = 'sale_realization'
                AND m.is_active = true
             WHERE r.company_id = :companyId
               AND r.period_from >= :periodFrom
               AND r.period_to   <= :periodTo
               {$filter}",
            $params,
        );

        $offset = ($page - 1) * self::PAGE_SIZE;
        $params['limit']  = self::PAGE_SIZE;
        $params['offset'] = $offset;

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT
                r.id,
                r.sku,
                r.offer_id,
                r.name,
                r.quantity,
                r.seller_price_per_instance,
                r.seller_price_per_instance     AS price_per_instance,
                (r.seller_price_per_instance
                    * r.quantity)::numeric(15,2) AS seller_total,
                r.total_amount,
                r.period_from::text             AS period_from,
                r.period_to::text               AS period_to,
                m.pl_category_id,
                cat.name                        AS pl_category_name,
                m.is_negative,
                r.pl_document_id                AS document_id
            FROM marketplace_ozon_realizations r
            INNER JOIN marketplace_sale_mappings m
                ON m.company_id = r.company_id
               AND m.marketplace = 'ozon'
               AND m.amount_source = 'sale_realization'
               AND m.is_active = true
            INNER JOIN pl_categories cat ON cat.id = m.pl_category_id
            WHERE r.company_id = :companyId
              AND r.period_from >= :periodFrom
              AND r.period_to   <= :periodTo
              {$filter}
            ORDER BY r.offer_id ASC, r.period_from ASC
            LIMIT :limit OFFSET :offset
            SQL,
            $params,
        );

        return $this->paginate($rows, $total, $page);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function aggregateTable(
        string $table,
        string $dateColumn,
        string $sourceType,
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
        ?bool $processed,
        ?string $documentId,
    ): array {
        $params = [
            'companyId'     => $companyId,
            'marketplace'   => $marketplace,
            'periodFrom'    => $periodFrom,
            'periodTo'      => $periodTo,
            'operationType' => $sourceType,
        ];

        $filter = $this->buildProcessedFilter('s.document_id', $processed, $documentId, $params);

        $amountExpr = $sourceType === 'sale'
            ? "CASE m.amount_source
                   WHEN 'sale_gross'      THEN s.price_per_unit * s.quantity
                   WHEN 'sale_revenue'    THEN s.total_revenue
                   WHEN 'sale_cost_price' THEN COALESCE(s.cost_price, 0) * s.quantity
                   ELSE 0
               END"
            : "CASE m.amount_source
                   WHEN 'return_refund'     THEN COALESCE(s.refund_amount, 0)
                   WHEN 'return_gross'      THEN COALESCE(ms.price_per_unit * s.quantity, s.refund_amount, 0)
                   WHEN 'return_cost_price' THEN COALESCE(ms.cost_price * s.quantity, 0)
                   ELSE 0
               END";

        $joinSales = $sourceType === 'return'
            ? 'LEFT JOIN marketplace_sales ms ON ms.id = s.sale_id'
            : '';

        $sql = <<<SQL
            SELECT
                :sourceType                                     AS source_type,
                m.amount_source,
                m.pl_category_id,
                cat.name                                        AS pl_category_name,
                m.is_negative,
                COUNT(s.id)                                     AS records_count,
                SUM({$amountExpr})::numeric(15,2)              AS total_amount,
                array_agg(DISTINCT s.document_id)
                    FILTER (WHERE s.document_id IS NOT NULL)    AS document_ids
            FROM {$table} s
            {$joinSales}
            INNER JOIN marketplace_sale_mappings m
                ON m.company_id = s.company_id
               AND m.marketplace = s.marketplace
               AND m.operation_type = :operationType
               AND m.is_active = true
            INNER JOIN pl_categories cat ON cat.id = m.pl_category_id
            WHERE s.company_id = :companyId
              AND s.marketplace = :marketplace
              AND s.{$dateColumn} >= :periodFrom
              AND s.{$dateColumn} <= :periodTo
              {$filter}
            GROUP BY m.amount_source, m.pl_category_id, cat.name, m.is_negative
            ORDER BY cat.name ASC
        SQL;

        $params['sourceType'] = $sourceType;

        return array_map(
            fn (array $row) => $this->normalizeDocumentIds($row),
            $this->connection->fetchAllAssociative($sql, $params),
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeDocumentIds(array $row): array
    {
        $raw = $row['document_ids'] ?? null;
        $row['document_ids'] = $raw
            ? array_values(array_filter(explode(',', trim((string) $raw, '{}'))))
            : [];

        return $row;
    }

    /** @param array<string,mixed> $params */
    private function buildProcessedFilter(
        string $column,
        ?bool $processed,
        ?string $documentId,
        array &$params,
    ): string {
        if ($documentId !== null) {
            $params['documentId'] = $documentId;

            return "AND {$column} = :documentId";
        }

        if ($processed === false) {
            return "AND {$column} IS NULL";
        }

        if ($processed === true) {
            return "AND {$column} IS NOT NULL";
        }

        return '';
    }

    /** @param list<array<string,mixed>> $rows */
    private function paginate(array $rows, int $total, int $page): array
    {
        return [
            'rows'  => $rows,
            'total' => $total,
            'page'  => $page,
            'pages' => max(1, (int) ceil($total / self::PAGE_SIZE)),
        ];
    }
}

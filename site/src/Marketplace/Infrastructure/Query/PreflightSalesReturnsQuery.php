<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * DBAL-запросы для preflight проверок этапа SALES_RETURNS.
 *
 * Проверяет:
 *   - количество продаж за период
 *   - количество продаж без себестоимости (блокирующее)
 *   - количество возвратов за период
 *   - количество возвратов без себестоимости (блокирующее)
 *   - загружена ли реализация Ozon за месяц (предупреждение)
 */
final class PreflightSalesReturnsQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function getSalesStats(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        return $this->connection->fetchAssociative(
            'SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE cost_price IS NULL OR cost_price = 0) AS without_cost,
                COUNT(*) FILTER (WHERE document_id IS NOT NULL) AS already_processed
             FROM marketplace_sales
             WHERE company_id = :companyId
               AND marketplace = :marketplace
               AND sale_date >= :periodFrom
               AND sale_date <= :periodTo',
            [
                'companyId'   => $companyId,
                'marketplace' => $marketplace,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
            ],
        ) ?: ['total' => 0, 'without_cost' => 0, 'already_processed' => 0];
    }

    public function getReturnsStats(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        return $this->connection->fetchAssociative(
            'SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE cost_price IS NULL OR cost_price = 0) AS without_cost,
                COUNT(*) FILTER (WHERE document_id IS NOT NULL) AS already_processed
             FROM marketplace_returns
             WHERE company_id = :companyId
               AND marketplace = :marketplace
               AND return_date >= :periodFrom
               AND return_date <= :periodTo',
            [
                'companyId'   => $companyId,
                'marketplace' => $marketplace,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
            ],
        ) ?: ['total' => 0, 'without_cost' => 0, 'already_processed' => 0];
    }

    /**
     * @return array<int, array{marketplace_sku: string|null, supplier_sku: string|null, count: int, orphan?: true}>
     */
    public function getSalesWithoutCostSkus(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        $params = [
            'companyId'   => $companyId,
            'marketplace' => $marketplace,
            'periodFrom'  => $periodFrom,
            'periodTo'    => $periodTo,
        ];

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                ml.marketplace_sku,
                ml.supplier_sku,
                COUNT(s.id) AS count
            FROM marketplace_sales s
            LEFT JOIN marketplace_listings ml ON ml.id = s.listing_id
            WHERE s.company_id = :companyId
              AND s.marketplace = :marketplace
              AND s.sale_date >= :periodFrom
              AND s.sale_date <= :periodTo
              AND (s.cost_price IS NULL OR s.cost_price = 0)
              AND ml.marketplace_sku IS NOT NULL
            GROUP BY ml.marketplace_sku, ml.supplier_sku
            ORDER BY COUNT(s.id) DESC
            SQL,
            $params,
        );

        $orphanCount = (int) $this->connection->fetchOne(
            <<<'SQL'
            SELECT COUNT(s.id)
            FROM marketplace_sales s
            LEFT JOIN marketplace_listings ml ON ml.id = s.listing_id
            WHERE s.company_id = :companyId
              AND s.marketplace = :marketplace
              AND s.sale_date >= :periodFrom
              AND s.sale_date <= :periodTo
              AND (s.cost_price IS NULL OR s.cost_price = 0)
              AND ml.marketplace_sku IS NULL
            SQL,
            $params,
        );

        if ($orphanCount > 0) {
            $rows[] = ['marketplace_sku' => null, 'supplier_sku' => null, 'count' => $orphanCount, 'orphan' => true];
        }

        return $rows;
    }

    /**
     * @return array<int, array{marketplace_sku: string|null, supplier_sku: string|null, count: int, orphan?: true}>
     */
    public function getReturnsWithoutCostSkus(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        $params = [
            'companyId'   => $companyId,
            'marketplace' => $marketplace,
            'periodFrom'  => $periodFrom,
            'periodTo'    => $periodTo,
        ];

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                ml.marketplace_sku,
                ml.supplier_sku,
                COUNT(r.id) AS count
            FROM marketplace_returns r
            LEFT JOIN marketplace_listings ml ON ml.id = r.listing_id
            WHERE r.company_id = :companyId
              AND r.marketplace = :marketplace
              AND r.return_date >= :periodFrom
              AND r.return_date <= :periodTo
              AND (r.cost_price IS NULL OR r.cost_price = 0)
              AND ml.marketplace_sku IS NOT NULL
            GROUP BY ml.marketplace_sku, ml.supplier_sku
            ORDER BY COUNT(r.id) DESC
            SQL,
            $params,
        );

        $orphanCount = (int) $this->connection->fetchOne(
            <<<'SQL'
            SELECT COUNT(r.id)
            FROM marketplace_returns r
            LEFT JOIN marketplace_listings ml ON ml.id = r.listing_id
            WHERE r.company_id = :companyId
              AND r.marketplace = :marketplace
              AND r.return_date >= :periodFrom
              AND r.return_date <= :periodTo
              AND (r.cost_price IS NULL OR r.cost_price = 0)
              AND ml.marketplace_sku IS NULL
            SQL,
            $params,
        );

        if ($orphanCount > 0) {
            $rows[] = ['marketplace_sku' => null, 'supplier_sku' => null, 'count' => $orphanCount, 'orphan' => true];
        }

        return $rows;
    }

    /**
     * Проверить загружена и обработана ли реализация Ozon за месяц.
     * Проверяем наличие денормализованных строк в marketplace_ozon_realizations.
     */
    public function isOzonRealizationLoaded(
        string $companyId,
        int $year,
        int $month,
    ): bool {
        $periodFrom = sprintf('%d-%02d-01', $year, $month);
        $periodTo   = (new \DateTimeImmutable($periodFrom))->modify('last day of this month')->format('Y-m-d');

        $result = $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM marketplace_ozon_realizations
             WHERE company_id = :companyId
               AND period_from >= :periodFrom
               AND period_to <= :periodTo',
            [
                'companyId'  => $companyId,
                'periodFrom' => $periodFrom,
                'periodTo'   => $periodTo,
            ],
        );

        return (int) $result > 0;
    }
}

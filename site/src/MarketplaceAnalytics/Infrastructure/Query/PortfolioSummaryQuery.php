<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Infrastructure\Query;

use Doctrine\DBAL\Connection;

final readonly class PortfolioSummaryQuery
{
    private const array DEFAULTS = [
        'total_revenue' => '0.00',
        'total_refunds' => '0.00',
        'total_sales_quantity' => 0,
        'total_listings' => 0,
        'total_profit' => null,
    ];

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array{
     *   total_revenue: string,
     *   total_refunds: string,
     *   total_sales_quantity: int,
     *   total_listings: int,
     *   total_profit: string|null,
     * }
     */
    public function fetch(
        string $companyId,
        ?string $marketplace,
        string $dateFrom,
        string $dateTo,
    ): array {
        $marketplaceCondition = $marketplace !== null ? 'AND marketplace = :marketplace' : '';

        $sql = <<<SQL
            SELECT
                COALESCE(SUM(revenue), '0.00')    AS total_revenue,
                COALESCE(SUM(refunds), '0.00')    AS total_refunds,
                COALESCE(SUM(sales_quantity), 0)   AS total_sales_quantity,
                COUNT(DISTINCT listing_id)         AS total_listings,
                CASE
                    WHEN COUNT(*) FILTER (WHERE cost_price IS NULL) > 0 THEN NULL
                    ELSE COALESCE(SUM(revenue), 0) - COALESCE(SUM(refunds), 0) - COALESCE(SUM(total_cost_price), 0)
                         - COALESCE(SUM((cost_breakdown->>'logistics_to')::numeric), 0)
                         - COALESCE(SUM((cost_breakdown->>'logistics_back')::numeric), 0)
                         - COALESCE(SUM((cost_breakdown->>'storage')::numeric), 0)
                         - COALESCE(SUM((cost_breakdown->>'advertising_cpc')::numeric), 0)
                         - COALESCE(SUM((cost_breakdown->>'advertising_other')::numeric), 0)
                         - COALESCE(SUM((cost_breakdown->>'advertising_external')::numeric), 0)
                         - COALESCE(SUM((cost_breakdown->>'commission')::numeric), 0)
                         - COALESCE(SUM((cost_breakdown->>'other')::numeric), 0)
                END AS total_profit
            FROM listing_daily_snapshots
            WHERE company_id  = :companyId
              {$marketplaceCondition}
              AND snapshot_date BETWEEN :dateFrom AND :dateTo
            SQL;

        $params = [
            'companyId' => $companyId,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ];

        if ($marketplace !== null) {
            $params['marketplace'] = $marketplace;
        }

        $row = $this->connection->fetchAssociative($sql, $params);

        if ($row === false) {
            return self::DEFAULTS;
        }

        return [
            'total_revenue' => (string) $row['total_revenue'],
            'total_refunds' => (string) $row['total_refunds'],
            'total_sales_quantity' => (int) $row['total_sales_quantity'],
            'total_listings' => (int) $row['total_listings'],
            'total_profit' => $row['total_profit'] !== null ? (string) $row['total_profit'] : null,
        ];
    }
}

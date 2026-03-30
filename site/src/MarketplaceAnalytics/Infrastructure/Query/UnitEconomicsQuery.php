<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class UnitEconomicsQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function buildQueryBuilder(
        string $companyId,
        string $marketplace,
        string $dateFrom,
        string $dateTo,
    ): QueryBuilder {
        return $this->connection->createQueryBuilder()
            ->select(
                's.listing_id',
                'l.name AS listing_name',
                'l.marketplace_sku AS marketplace_sku',
                'SUM(s.revenue) AS revenue',
                'SUM(s.refunds) AS refunds',
                'SUM(s.sales_quantity) AS sales_quantity',
                'SUM(s.returns_quantity) AS returns_quantity',
                'SUM(s.orders_quantity) AS orders_quantity',
                'SUM(s.delivered_quantity) AS delivered_quantity',
                "CASE WHEN SUM(s.sales_quantity) > 0 THEN SUM(s.avg_sale_price * s.sales_quantity) / SUM(s.sales_quantity) ELSE 0 END AS avg_sale_price",
                "CASE WHEN COUNT(*) FILTER (WHERE s.cost_price IS NULL) > 0 THEN NULL ELSE SUM(s.total_cost_price) END AS total_cost_price",
                'AVG(s.cost_price) AS avg_cost_price',
                "SUM((s.cost_breakdown->>'logistics_to')::numeric) AS logistics_to",
                "SUM((s.cost_breakdown->>'logistics_back')::numeric) AS logistics_back",
                "SUM((s.cost_breakdown->>'storage')::numeric) AS storage",
                "SUM((s.cost_breakdown->>'advertising_cpc')::numeric) AS advertising_cpc",
                "SUM((s.cost_breakdown->>'advertising_other')::numeric) AS advertising_other",
                "SUM((s.cost_breakdown->>'advertising_external')::numeric) AS advertising_external",
                "SUM((s.cost_breakdown->>'commission')::numeric) AS commission",
                "SUM((s.cost_breakdown->>'other')::numeric) AS other_costs",
                "BOOL_OR(s.data_quality::jsonb != '[]'::jsonb) AS has_quality_issues",
                'COUNT(*) AS snapshots_count',
            )
            ->from('listing_daily_snapshots', 's')
            ->innerJoin('s', 'marketplace_listings', 'l', 'l.id = s.listing_id AND l.company_id = s.company_id')
            ->where('s.company_id = :companyId')
            ->andWhere('s.marketplace = :marketplace')
            ->andWhere('s.snapshot_date BETWEEN :dateFrom AND :dateTo')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('dateFrom', $dateFrom)
            ->setParameter('dateTo', $dateTo)
            ->groupBy('s.listing_id', 'l.name', 'l.marketplace_sku')
            ->orderBy('revenue', 'DESC');
    }
}

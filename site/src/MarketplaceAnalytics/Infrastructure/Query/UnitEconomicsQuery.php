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
        ?string $marketplace,
        string $dateFrom,
        string $dateTo,
    ): QueryBuilder {
        $qb = $this->connection->createQueryBuilder()
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
                "COALESCE(SUM((s.cost_breakdown->>'logistics_to')::numeric), 0) AS logistics_to",
                "COALESCE(SUM((s.cost_breakdown->>'logistics_back')::numeric), 0) AS logistics_back",
                "COALESCE(SUM((s.cost_breakdown->>'storage')::numeric), 0) AS storage",
                "COALESCE(SUM((s.cost_breakdown->>'advertising_cpc')::numeric), 0) AS advertising_cpc",
                "COALESCE(SUM((s.cost_breakdown->>'advertising_other')::numeric), 0) AS advertising_other",
                "COALESCE(SUM((s.cost_breakdown->>'advertising_external')::numeric), 0) AS advertising_external",
                "COALESCE(SUM((s.cost_breakdown->>'commission')::numeric), 0) AS commission",
                "COALESCE(SUM((s.cost_breakdown->>'other')::numeric), 0) AS other_costs",
                "BOOL_OR(s.data_quality != '[]'::jsonb) AS has_quality_issues",
                'COUNT(*) AS snapshots_count',
            )
            ->from('listing_daily_snapshots', 's')
            ->innerJoin('s', 'marketplace_listings', 'l', 'l.id = s.listing_id AND l.company_id = s.company_id')
            ->where('s.company_id = :companyId')
            ->andWhere('s.snapshot_date BETWEEN :dateFrom AND :dateTo')
            ->setParameter('companyId', $companyId)
            ->setParameter('dateFrom', $dateFrom)
            ->setParameter('dateTo', $dateTo)
            ->groupBy('s.listing_id')
            ->addGroupBy('l.name')
            ->addGroupBy('l.marketplace_sku')
            ->having('SUM(s.sales_quantity) > 0 OR SUM(s.revenue) > 0 OR SUM(s.refunds) > 0 OR SUM(s.orders_quantity) > 0')
            ->orderBy('SUM(s.revenue)', 'DESC');

        if ($marketplace !== null) {
            $qb->andWhere('s.marketplace = :marketplace')
                ->setParameter('marketplace', $marketplace);
        }

        return $qb;
    }
}

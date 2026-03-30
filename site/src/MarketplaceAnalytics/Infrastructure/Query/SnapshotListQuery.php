<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class SnapshotListQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function buildQueryBuilder(
        string $companyId,
        ?string $marketplace,
        ?string $dateFrom,
        ?string $dateTo,
        ?string $listingId,
    ): QueryBuilder {
        $qb = $this->connection->createQueryBuilder()
            ->select(
                's.id',
                's.snapshot_date',
                's.marketplace',
                's.listing_id',
                'l.name AS listing_name',
                'l.marketplace_sku AS marketplace_sku',
                's.revenue',
                's.refunds',
                's.sales_quantity',
                's.returns_quantity',
                's.orders_quantity',
                's.delivered_quantity',
                's.avg_sale_price',
                's.cost_price',
                's.total_cost_price',
                "(s.cost_breakdown->>'logistics_to')::numeric AS logistics_to",
                "(s.cost_breakdown->>'logistics_back')::numeric AS logistics_back",
                "(s.cost_breakdown->>'storage')::numeric AS storage",
                "(s.cost_breakdown->>'advertising_cpc')::numeric AS advertising_cpc",
                "(s.cost_breakdown->>'advertising_other')::numeric AS advertising_other",
                "(s.cost_breakdown->>'commission')::numeric AS commission",
                "(s.cost_breakdown->>'other')::numeric AS other_costs",
                's.data_quality',
                's.calculated_at',
            )
            ->from('listing_daily_snapshots', 's')
            ->innerJoin('s', 'marketplace_listings', 'l', 'l.id = s.listing_id AND l.company_id = s.company_id')
            ->where('s.company_id = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('s.snapshot_date', 'DESC')
            ->addOrderBy('l.name', 'ASC');

        if ($marketplace !== null) {
            $qb->andWhere('s.marketplace = :marketplace')
                ->setParameter('marketplace', $marketplace);
        }

        if ($dateFrom !== null) {
            $qb->andWhere('s.snapshot_date >= :dateFrom')
                ->setParameter('dateFrom', $dateFrom);
        }

        if ($dateTo !== null) {
            $qb->andWhere('s.snapshot_date <= :dateTo')
                ->setParameter('dateTo', $dateTo);
        }

        if ($listingId !== null) {
            $qb->andWhere('s.listing_id = :listingId')
                ->setParameter('listingId', $listingId);
        }

        return $qb;
    }
}

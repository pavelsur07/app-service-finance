<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

final class SalesListQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function buildQueryBuilder(string $companyId, ?string $marketplace): QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder()
            ->select(
                's.id',
                's.sale_date',
                's.marketplace',
                's.external_order_id',
                's.quantity',
                's.price_per_unit',
                's.total_revenue',
                's.cost_price',
                'l.marketplace_sku',
                'l.name AS listing_name',
            )
            ->from('marketplace_sales', 's')
            ->innerJoin('s', 'marketplace_listings', 'l', 's.listing_id = l.id')
            ->where('s.company_id = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('s.sale_date', 'DESC');

        if ($marketplace !== null) {
            $qb->andWhere('s.marketplace = :marketplace')
                ->setParameter('marketplace', $marketplace);
        }

        return $qb;
    }
}

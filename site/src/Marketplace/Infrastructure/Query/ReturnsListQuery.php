<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

final class ReturnsListQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function buildQueryBuilder(string $companyId, ?string $marketplace): QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder()
            ->select(
                'r.id',
                'r.return_date',
                'r.marketplace',
                'r.external_return_id',
                'r.document_id',
                'r.quantity',
                'r.refund_amount',
                'r.cost_price',
                'r.return_reason',
                'l.marketplace_sku',
                'l.name AS listing_name',
            )
            ->from('marketplace_returns', 'r')
            ->innerJoin('r', 'marketplace_listings', 'l', 'r.listing_id = l.id')
            ->where('r.company_id = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('r.return_date', 'DESC');

        if ($marketplace !== null) {
            $qb->andWhere('r.marketplace = :marketplace')
                ->setParameter('marketplace', $marketplace);
        }

        return $qb;
    }
}

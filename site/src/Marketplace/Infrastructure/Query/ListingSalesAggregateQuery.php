<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\DTO\ListingSalesAggregateDTO;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class ListingSalesAggregateQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array<string, ListingSalesAggregateDTO> keyed by listingId
     */
    public function executeByPeriod(
        string $companyId,
        ?string $marketplace,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $mpFilter = $marketplace !== null ? 'AND s.marketplace = :marketplace' : '';

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT
                s.listing_id,
                l.name                AS listing_title,
                l.marketplace_sku     AS listing_sku,
                l.marketplace         AS listing_marketplace,
                SUM(s.total_revenue)  AS revenue,
                SUM(s.quantity)       AS quantity,
                SUM(CASE WHEN s.cost_price IS NOT NULL THEN s.cost_price * s.quantity ELSE 0 END) AS cost_price_total,
                SUM(CASE WHEN s.cost_price IS NOT NULL THEN s.quantity ELSE 0 END) AS cost_price_quantity
            FROM marketplace_sales s
            JOIN marketplace_listings l ON l.id = s.listing_id
            WHERE s.company_id = :companyId
              AND s.sale_date >= :periodFrom
              AND s.sale_date <= :periodTo
              {$mpFilter}
            GROUP BY s.listing_id, l.name, l.marketplace_sku, l.marketplace
            SQL,
            array_filter([
                'companyId'   => $companyId,
                'periodFrom'  => $from->format('Y-m-d'),
                'periodTo'    => $to->format('Y-m-d'),
                'marketplace' => $marketplace,
            ], static fn ($v) => $v !== null),
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['listing_id']] = new ListingSalesAggregateDTO(
                listingId:         $row['listing_id'],
                title:             $row['listing_title'],
                sku:               $row['listing_sku'],
                marketplace:       $row['listing_marketplace'],
                revenue:           (string) $row['revenue'],
                quantity:          (int) $row['quantity'],
                costPriceTotal:    (string) $row['cost_price_total'],
                costPriceQuantity: (int) $row['cost_price_quantity'],
            );
        }

        return $result;
    }
}

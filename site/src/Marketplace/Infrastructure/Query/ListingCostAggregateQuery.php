<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\DTO\ListingCostCategoryAggregateDTO;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class ListingCostAggregateQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array<string, list<ListingCostCategoryAggregateDTO>> keyed by listingId
     */
    public function executeByPeriod(
        string $companyId,
        ?string $marketplace,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $mpFilter = $marketplace !== null ? 'AND c.marketplace = :marketplace' : '';

        // net_amount / costs_amount / storno_amount — классификация по operation_type.
        // После Phase 2B operation_type гарантированно NOT NULL (см. UnprocessedCostsQuery).
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT
                c.listing_id,
                cc.code                                                       AS category_code,
                cc.name                                                       AS category_name,
                SUM(CASE
                    WHEN (c.operation_type = 'storno')
                    THEN -ABS(c.amount)
                    ELSE ABS(c.amount)
                END)                                                          AS net_amount,
                SUM(CASE
                    WHEN (c.operation_type = 'storno')
                    THEN 0
                    ELSE ABS(c.amount)
                END)                                                          AS costs_amount,
                SUM(CASE
                    WHEN (c.operation_type = 'storno')
                    THEN ABS(c.amount)
                    ELSE 0
                END)                                                          AS storno_amount
            FROM marketplace_costs c
            JOIN marketplace_cost_categories cc ON cc.id = c.category_id
            WHERE c.company_id = :companyId
              AND c.cost_date >= :periodFrom
              AND c.cost_date <= :periodTo
              AND c.listing_id IS NOT NULL
              {$mpFilter}
            GROUP BY c.listing_id, cc.code, cc.name
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
            $result[$row['listing_id']][] = new ListingCostCategoryAggregateDTO(
                listingId:     $row['listing_id'],
                categoryCode:  $row['category_code'],
                categoryName:  $row['category_name'],
                netAmount:     (string) $row['net_amount'],
                costsAmount:   (string) $row['costs_amount'],
                stornoAmount:  (string) $row['storno_amount'],
            );
        }

        return $result;
    }
}

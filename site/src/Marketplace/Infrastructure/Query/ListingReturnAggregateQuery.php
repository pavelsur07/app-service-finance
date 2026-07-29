<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\DTO\ListingReturnAggregateDTO;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\DBAL\Connection;

final readonly class ListingReturnAggregateQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array<string, ListingReturnAggregateDTO> keyed by listingId
     */
    public function executeByPeriod(
        string $companyId,
        ?string $marketplace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $mpFilter = null !== $marketplace ? 'AND r.marketplace = :marketplace' : '';

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT
                r.listing_id,
                SUM(
                    -- WB refund_amount stores the per-unit amount before SPP.
                    -- Other marketplaces keep the existing refund_amount contract.
                    CASE
                        WHEN r.marketplace = :wbMarketplace THEN r.refund_amount * r.quantity
                        ELSE r.refund_amount
                    END
                ) AS returns_total,
                SUM(r.quantity)      AS returns_quantity
            FROM marketplace_returns r
            WHERE r.company_id = :companyId
              AND r.return_date >= :periodFrom
              AND r.return_date <= :periodTo
              {$mpFilter}
            GROUP BY r.listing_id
            SQL,
            array_filter([
                'companyId' => $companyId,
                'periodFrom' => $from->format('Y-m-d'),
                'periodTo' => $to->format('Y-m-d'),
                'marketplace' => $marketplace,
                'wbMarketplace' => MarketplaceType::WILDBERRIES->value,
            ], static fn ($v) => null !== $v),
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['listing_id']] = new ListingReturnAggregateDTO(
                listingId: $row['listing_id'],
                returnsTotal: (string) $row['returns_total'],
                returnsQuantity: (int) $row['returns_quantity'],
            );
        }

        return $result;
    }
}

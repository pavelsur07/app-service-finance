<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Query;

use App\Inventory\Enum\StockStatus;
use Doctrine\DBAL\Connection;

final readonly class StockQtyByListingOnDateQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array<string, float> listingId => stockQty
     */
    public function execute(string $companyId, \DateTimeImmutable $reportDate): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'WITH latest_sessions AS (
                SELECT DISTINCT ON (candidate.source)
                    candidate.source,
                    candidate.snapshot_session_id
                FROM inventory_stock_snapshots candidate
                WHERE candidate.company_id = :companyId
                  AND candidate.snapshot_date <= :reportDate
                ORDER BY
                    candidate.source,
                    candidate.snapshot_date DESC,
                    candidate.snapshot_at DESC,
                    candidate.snapshot_session_id DESC
             )
             SELECT s.listing_id, SUM(s.quantity) AS stock_qty
             FROM inventory_stock_snapshots s
             INNER JOIN latest_sessions latest
                ON latest.source = s.source
               AND latest.snapshot_session_id = s.snapshot_session_id
             WHERE s.company_id = :companyId
               AND s.status = :status
               AND s.listing_id IS NOT NULL
             GROUP BY s.listing_id',
            [
                'companyId' => $companyId,
                'reportDate' => $reportDate->format('Y-m-d'),
                'status' => StockStatus::Available->value,
            ],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['listing_id']] = round((float) $row['stock_qty'], 3);
        }

        return $result;
    }
}

<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

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
        $snapshotDate = $this->connection->fetchOne(
            'SELECT MAX(s.snapshot_date)
             FROM inventory_stock_snapshots s
             WHERE s.company_id = :companyId
               AND s.snapshot_date <= :reportDate
               AND s.listing_id IS NOT NULL',
            [
                'companyId' => $companyId,
                'reportDate' => $reportDate->format('Y-m-d'),
            ]
        );

        if (!is_string($snapshotDate) || '' === $snapshotDate) {
            return [];
        }

        $snapshotAtRow = $this->connection->fetchAssociative(
            'SELECT MAX(s.snapshot_at) AS snapshot_at
             FROM inventory_stock_snapshots s
             WHERE s.company_id = :companyId
               AND s.snapshot_date = :snapshotDate
               AND s.listing_id IS NOT NULL',
            [
                'companyId' => $companyId,
                'snapshotDate' => $snapshotDate,
            ],
        );

        $snapshotAt = is_array($snapshotAtRow) ? ($snapshotAtRow['snapshot_at'] ?? null) : null;
        if (!is_string($snapshotAt) || '' === $snapshotAt) {
            return [];
        }

        $snapshotAtDateTime = new \DateTimeImmutable($snapshotAt);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.listing_id, SUM(s.quantity) AS stock_qty
             FROM inventory_stock_snapshots s
             WHERE s.company_id = :companyId
               AND s.snapshot_date = :snapshotDate
               AND s.snapshot_at = :snapshotAt
               AND s.listing_id IS NOT NULL
             GROUP BY s.listing_id',
            [
                'companyId' => $companyId,
                'snapshotDate' => $snapshotDate,
                'snapshotAt' => $snapshotAtDateTime,
            ],
            [
                'snapshotAt' => Types::DATETIME_IMMUTABLE,
            ],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['listing_id']] = round((float) $row['stock_qty'], 3);
        }

        return $result;
    }
}

<?php

declare(strict_types=1);

namespace App\Inventory\Repository;

use App\Inventory\Entity\StockSnapshot;
use Doctrine\DBAL\Connection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class StockSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockSnapshot::class);
    }

    public function upsertDaySnapshot(StockSnapshot $snapshot): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO inventory_stock_snapshots (
                id, company_id, snapshot_session_id, snapshot_date, snapshot_at,
                listing_id, product_id, location_id, status, quantity, reserved_quantity,
                source, source_sku, source_offer_id, fulfillment_type, mapping_status,
                raw_snapshot_id, created_at
             ) VALUES (
                :id, :companyId, :snapshotSessionId, :snapshotDate, :snapshotAt,
                :listingId, :productId, :locationId, :status, :quantity, :reservedQuantity,
                :source, :sourceSku, :sourceOfferId, :fulfillmentType, :mappingStatus,
                :rawSnapshotId, :createdAt
             )
             ON CONFLICT (
                company_id,
                snapshot_date,
                source,
                source_sku,
                fulfillment_type,
                location_id,
                status
             )
             DO UPDATE SET
                snapshot_session_id = EXCLUDED.snapshot_session_id,
                snapshot_at = EXCLUDED.snapshot_at,
                listing_id = EXCLUDED.listing_id,
                product_id = EXCLUDED.product_id,
                quantity = EXCLUDED.quantity,
                reserved_quantity = EXCLUDED.reserved_quantity,
                source_offer_id = EXCLUDED.source_offer_id,
                mapping_status = EXCLUDED.mapping_status,
                raw_snapshot_id = EXCLUDED.raw_snapshot_id',
            [
                'id' => $snapshot->getId(),
                'companyId' => $snapshot->getCompanyId(),
                'snapshotSessionId' => $snapshot->getSnapshotSessionId(),
                'snapshotDate' => $snapshot->getSnapshotDate(),
                'snapshotAt' => $snapshot->getSnapshotAt(),
                'listingId' => $snapshot->getListingId(),
                'productId' => $snapshot->getProductId(),
                'locationId' => $snapshot->getLocationId(),
                'status' => $snapshot->getStatus()->value,
                'quantity' => $snapshot->getQuantity(),
                'reservedQuantity' => $snapshot->getReservedQuantity(),
                'source' => $snapshot->getSource()->value,
                'sourceSku' => $snapshot->getSourceSku(),
                'sourceOfferId' => $snapshot->getSourceOfferId(),
                'fulfillmentType' => $snapshot->getFulfillmentType(),
                'mappingStatus' => $snapshot->getMappingStatus()->value,
                'rawSnapshotId' => $snapshot->getRawSnapshotId(),
                'createdAt' => $snapshot->getCreatedAt(),
            ],
            [
                'snapshotDate' => 'date_immutable',
                'snapshotAt' => 'datetime_immutable',
                'createdAt' => 'datetime_immutable',
                'listingId' => Connection::PARAM_STR,
                'productId' => Connection::PARAM_STR,
            ],
        );
    }
}

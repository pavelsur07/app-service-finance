<?php

declare(strict_types=1);

namespace App\Inventory\Repository;

use App\Inventory\Entity\StockSnapshot;
use App\Inventory\Enum\StockStatus;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
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
                'listingId' => ParameterType::STRING,
                'productId' => ParameterType::STRING,
            ],
        );
    }

    /**
     * Последняя сессия дня по строкам «на складе» — та, что видят отчёты по правилу «последняя
     * сессия дня»: snapshot_at DESC, snapshot_session_id DESC.
     *
     * @return array{sessionId: string, snapshotAt: \DateTimeImmutable}|null
     */
    public function findLatestAvailableDaySession(string $companyId, MarketplaceType $source, \DateTimeImmutable $snapshotDate): ?array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT snapshot_session_id, snapshot_at
             FROM inventory_stock_snapshots
             WHERE company_id = :companyId AND source = :source AND snapshot_date = :snapshotDate AND status = :status
             ORDER BY snapshot_at DESC, snapshot_session_id DESC
             LIMIT 1',
            ['companyId' => $companyId, 'source' => $source->value, 'snapshotDate' => $snapshotDate, 'status' => StockStatus::Available->value],
            ['snapshotDate' => 'date_immutable'],
        );
        if (false === $row) {
            return null;
        }

        return [
            'sessionId' => (string) $row['snapshot_session_id'],
            'snapshotAt' => new \DateTimeImmutable((string) $row['snapshot_at']),
        ];
    }

    /** Удаляет строки дня одного статуса — замена окна перед повторной записью. */
    public function deleteDayRowsByStatus(string $companyId, MarketplaceType $source, \DateTimeImmutable $snapshotDate, StockStatus $status): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM inventory_stock_snapshots
             WHERE company_id = :companyId AND source = :source AND snapshot_date = :snapshotDate AND status = :status',
            ['companyId' => $companyId, 'source' => $source->value, 'snapshotDate' => $snapshotDate, 'status' => $status->value],
            ['snapshotDate' => 'date_immutable'],
        );
    }
}

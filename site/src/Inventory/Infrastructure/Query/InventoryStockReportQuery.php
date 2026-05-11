<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Query;

use App\Inventory\Enum\StockSnapshotMappingStatus;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Pagerfanta\Doctrine\DBAL\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Webmozart\Assert\Assert;

final class InventoryStockReportQuery
{
    public const PER_PAGE = 30;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function findLatestSnapshotSessionId(string $companyId, MarketplaceType $source): ?string
    {
        Assert::uuid($companyId);

        $value = $this->connection->createQueryBuilder()
            ->select('s.snapshot_session_id')
            ->from('inventory_stock_snapshots', 's')
            ->where('s.company_id = :companyId')
            ->andWhere('s.source = :source')
            ->setParameter('companyId', $companyId)
            ->setParameter('source', $source->value)
            ->orderBy('s.snapshot_at', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $value !== false ? (string) $value : null;
    }

    public function getPage(
        string $companyId,
        int $page,
        int $perPage,
        MarketplaceType $source,
        ?string $snapshotSessionId,
        ?\DateTimeImmutable $snapshotAt,
        ?string $search,
        ?StockSnapshotMappingStatus $mappingStatus,
    ): Pagerfanta {
        $qb = $this->buildQueryBuilder(
            companyId: $companyId,
            source: $source,
            snapshotSessionId: $snapshotSessionId,
            snapshotAt: $snapshotAt,
            search: $search,
            mappingStatus: $mappingStatus,
        );

        return Pagerfanta::createForCurrentPageWithMaxPerPage(
            new QueryAdapter($qb, static function (QueryBuilder $countQb): void {
                $countQb
                    ->select('COUNT(s.id) AS total_results')
                    ->resetOrderBy()
                    ->setMaxResults(1);
            }),
            max(1, $page),
            min(100, max(1, $perPage)),
        );
    }

    private function buildQueryBuilder(
        string $companyId,
        MarketplaceType $source,
        ?string $snapshotSessionId,
        ?\DateTimeImmutable $snapshotAt,
        ?string $search,
        ?StockSnapshotMappingStatus $mappingStatus,
    ): QueryBuilder {
        Assert::uuid($companyId);

        $qb = $this->connection->createQueryBuilder()
            ->select(
                's.id',
                's.snapshot_session_id',
                's.snapshot_at',
                's.source',
                's.source_sku',
                's.source_offer_id',
                's.listing_id',
                's.product_id',
                's.fulfillment_type',
                's.quantity',
                's.reserved_quantity',
                '(s.quantity - s.reserved_quantity) AS available_for_sale',
                's.mapping_status',
            )
            ->from('inventory_stock_snapshots', 's')
            ->where('s.company_id = :companyId')
            ->andWhere('s.source = :source')
            ->setParameter('companyId', $companyId)
            ->setParameter('source', $source->value)
            ->orderBy('s.snapshot_at', 'DESC')
            ->addOrderBy('s.id', 'DESC');

        if ($snapshotSessionId !== null) {
            Assert::uuid($snapshotSessionId);
            $qb->andWhere('s.snapshot_session_id = :snapshotSessionId')
                ->setParameter('snapshotSessionId', $snapshotSessionId);
        }

        if ($snapshotAt !== null) {
            $qb->andWhere('s.snapshot_at = :snapshotAt')
                ->setParameter('snapshotAt', $snapshotAt, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE);
        }

        if ($search !== null && '' !== trim($search)) {
            $qb->andWhere('(LOWER(s.source_sku) LIKE :search OR LOWER(s.source_offer_id) LIKE :search)')
                ->setParameter('search', '%'.mb_strtolower(trim($search)).'%');
        }

        if ($mappingStatus !== null) {
            $qb->andWhere('s.mapping_status = :mappingStatus')
                ->setParameter('mappingStatus', $mappingStatus->value);
        }

        return $qb;
    }
}

<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Query;

use App\Marketplace\Enum\MarketplaceType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
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

    /**
     * Остатки «на дату»: ближайший день со снимком не позже запрошенной даты.
     * null — снимков по источнику на эту дату и раньше нет.
     */
    public function findEffectiveSnapshotDate(string $companyId, MarketplaceType $source, \DateTimeImmutable $date): ?\DateTimeImmutable
    {
        Assert::uuid($companyId);

        // ORDER BY + LIMIT 1, а не MAX(): планировщик идёт обратным сканом по
        // idx_inventory_stock_company_source_date и останавливается на первой подходящей строке.
        $value = $this->connection->createQueryBuilder()
            ->select('s.snapshot_date')
            ->from('inventory_stock_snapshots', 's')
            ->where('s.company_id = :companyId')
            ->andWhere('s.source = :source')
            ->andWhere('s.snapshot_date <= :date')
            ->setParameter('companyId', $companyId)
            ->setParameter('source', $source->value)
            ->setParameter('date', $date, Types::DATE_IMMUTABLE)
            ->orderBy('s.snapshot_date', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        if (false === $value || null === $value) {
            return null;
        }

        return new \DateTimeImmutable((string) $value);
    }

    public function getPage(
        string $companyId,
        int $page,
        int $perPage,
        MarketplaceType $source,
        \DateTimeImmutable $snapshotDate,
    ): Pagerfanta {
        $qb = $this->buildQueryBuilder($companyId, $source, $snapshotDate);

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
        \DateTimeImmutable $snapshotDate,
    ): QueryBuilder {
        Assert::uuid($companyId);

        return $this->connection->createQueryBuilder()
            ->select(
                's.id',
                's.snapshot_session_id',
                's.snapshot_at',
                's.source',
                's.source_sku',
                's.source_offer_id',
                's.fulfillment_type',
                's.status',
                's.quantity',
                's.reserved_quantity',
                '(s.quantity - s.reserved_quantity) AS available_for_sale',
                's.mapping_status',
                'l.name AS location_name',
            )
            ->from('inventory_stock_snapshots', 's')
            ->innerJoin('s', 'inventory_locations', 'l', 'l.id = s.location_id AND l.company_id = s.company_id')
            ->where('s.company_id = :companyId')
            ->andWhere('s.source = :source')
            ->andWhere('s.snapshot_date = :snapshotDate')
            ->setParameter('companyId', $companyId)
            ->setParameter('source', $source->value)
            ->setParameter('snapshotDate', $snapshotDate, Types::DATE_IMMUTABLE)
            ->orderBy('s.snapshot_at', 'DESC')
            ->addOrderBy('s.id', 'DESC');
    }
}

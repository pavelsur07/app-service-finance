<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use App\Ingestion\Application\DTO\CoverageCellView;
use App\Ingestion\Application\DTO\ShopOptionView;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Webmozart\Assert\Assert;

final class CoverageQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<CoverageCellView>
     */
    public function heatmap(
        string $companyId,
        ?string $shopRef,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        Assert::uuid($companyId);

        $qb = $this->connection->createQueryBuilder()
            ->select(
                "TO_CHAR(ft.occurred_at, 'YYYY-MM-DD') AS record_date",
                'ft.shop_ref',
                'r.resource_type',
                'COUNT(DISTINCT r.id) AS raw_count',
                'COUNT(ft.id) AS tx_count',
                'COUNT(DISTINCT ni.id) AS issue_count',
                'MAX(r.fetched_at) AS last_fetched_at',
            )
            ->from('ingest_financial_transactions', 'ft')
            ->leftJoin(
                'ft',
                'ingest_raw_records',
                'r',
                'r.company_id = :companyId AND r.company_id = ft.company_id AND r.id = ft.raw_record_id',
            )
            ->leftJoin(
                'ft',
                'ingest_normalization_issues',
                'ni',
                'ni.company_id = :companyId AND ni.company_id = ft.company_id AND ni.raw_record_id = r.id AND ni.resolved_at IS NULL',
            )
            ->where('ft.company_id = :companyId')
            ->andWhere('ft.occurred_at >= :from')
            ->andWhere('ft.occurred_at < :toExclusive')
            ->setParameter('companyId', $companyId)
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->setParameter('toExclusive', $to->modify('+1 day'), Types::DATETIME_IMMUTABLE)
            ->groupBy('record_date', 'ft.shop_ref', 'r.resource_type')
            ->orderBy('record_date', 'ASC')
            ->addOrderBy('ft.shop_ref', 'ASC')
            ->addOrderBy('r.resource_type', 'ASC');

        if (null !== $shopRef && '' !== $shopRef) {
            $qb->andWhere('ft.shop_ref = :shopRef')
                ->setParameter('shopRef', $shopRef);
        }

        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_map(
            static fn (array $row): CoverageCellView => new CoverageCellView(
                date: (string) $row['record_date'],
                shopRef: (string) $row['shop_ref'],
                resourceType: (string) $row['resource_type'],
                rawCount: (int) $row['raw_count'],
                txCount: (int) $row['tx_count'],
                issueCount: (int) $row['issue_count'],
                lastFetchedAt: self::formatDateTime($row['last_fetched_at'] ?? null),
            ),
            $rows,
        );
    }

    /**
     * @return list<ShopOptionView>
     */
    public function shops(string $companyId): array
    {
        Assert::uuid($companyId);

        $rows = $this->connection->createQueryBuilder()
            ->select('r.shop_ref')
            ->from('ingest_raw_records', 'r')
            ->where('r.company_id = :companyId')
            ->andWhere('r.fetched_at >= :from')
            ->andWhere("r.shop_ref <> ''")
            ->setParameter('companyId', $companyId)
            ->setParameter('from', new \DateTimeImmutable('-90 days'), Types::DATETIME_IMMUTABLE)
            ->groupBy('r.shop_ref')
            ->orderBy('r.shop_ref', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): ShopOptionView => new ShopOptionView(
                shopRef: (string) $row['shop_ref'],
                label: (string) $row['shop_ref'],
            ),
            $rows,
        );
    }

    private static function formatDateTime(mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return (new \DateTimeImmutable((string) $value))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}

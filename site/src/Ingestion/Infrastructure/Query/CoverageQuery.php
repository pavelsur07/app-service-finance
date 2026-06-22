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

        $recordDate = "COALESCE(TO_CHAR(ft.occurred_at, 'YYYY-MM-DD'), TO_CHAR(raw_window.day, 'YYYY-MM-DD'), TO_CHAR(r.fetched_at, 'YYYY-MM-DD'))";
        $shopExpression = "COALESCE(NULLIF(ft.shop_ref, ''), r.shop_ref)";
        $dateFilter = <<<'SQL'
            (
                (ft.id IS NOT NULL AND ft.occurred_at >= :from AND ft.occurred_at < :toExclusive)
                OR (
                    ft.id IS NULL
                    AND (
                        (j.window_from IS NOT NULL AND j.window_from <= :toDate AND COALESCE(j.window_to, j.window_from) >= :fromDate)
                        OR (j.window_from IS NULL AND r.fetched_at >= :from AND r.fetched_at < :toExclusive)
                    )
                )
            )
            SQL;

        $qb = $this->connection->createQueryBuilder()
            ->select(
                $recordDate.' AS record_date',
                $shopExpression.' AS shop_ref',
                'r.resource_type',
                'COUNT(DISTINCT r.id) AS raw_count',
                'COUNT(DISTINCT ft.id) AS tx_count',
                'COUNT(DISTINCT ni.id) AS issue_count',
                'MAX(r.fetched_at) AS last_fetched_at',
            )
            ->from('ingest_raw_records', 'r')
            ->leftJoin(
                'r',
                'ingest_sync_jobs',
                'j',
                'j.company_id = r.company_id AND j.id::text = r.sync_job_id',
            )
            ->leftJoin(
                'r',
                'ingest_financial_transactions',
                'ft',
                'ft.company_id = r.company_id AND ft.raw_record_id = r.id',
            )
            ->leftJoin(
                'r',
                "LATERAL (
                    SELECT day::date AS day
                    FROM generate_series(
                        GREATEST(j.window_from, :fromDate)::date,
                        LEAST(COALESCE(j.window_to, j.window_from), :toDate)::date,
                        interval '1 day'
                    ) AS day
                    WHERE ft.id IS NULL AND j.window_from IS NOT NULL
                )",
                'raw_window',
                'true',
            )
            ->leftJoin(
                'r',
                'ingest_normalization_issues',
                'ni',
                'ni.company_id = r.company_id AND ni.raw_record_id = r.id AND ni.resolved_at IS NULL',
            )
            ->where('r.company_id = :companyId')
            ->andWhere($dateFilter)
            ->setParameter('companyId', $companyId)
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->setParameter('toExclusive', $to->modify('+1 day'), Types::DATETIME_IMMUTABLE)
            ->setParameter('fromDate', $from, Types::DATE_IMMUTABLE)
            ->setParameter('toDate', $to, Types::DATE_IMMUTABLE)
            ->groupBy($recordDate, $shopExpression, 'r.resource_type')
            ->orderBy('record_date', 'ASC')
            ->addOrderBy($shopExpression, 'ASC')
            ->addOrderBy('r.resource_type', 'ASC');

        if (null !== $shopRef && '' !== $shopRef) {
            $qb->andWhere($shopExpression.' = :shopRef')
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

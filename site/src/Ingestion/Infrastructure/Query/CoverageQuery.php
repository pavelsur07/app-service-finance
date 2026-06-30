<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use App\Ingestion\Application\DTO\CoverageCellView;
use App\Ingestion\Application\DTO\ShopOptionView;
use App\Ingestion\Application\Service\IngestionResourceLabelProvider;
use App\Ingestion\Enum\SyncJobKind;
use App\Ingestion\Enum\SyncJobStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Webmozart\Assert\Assert;

final class CoverageQuery
{
    public function __construct(
        private readonly Connection $connection,
        private readonly IngestionResourceLabelProvider $resourceLabelProvider,
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
                'MAX(GREATEST(r.fetched_at, COALESCE(r.last_seen_at, r.fetched_at))) AS last_fetched_at',
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
        $rows = $this->mergeRows($rows, $this->failedJobIssueRows($companyId, $shopRef, $from, $to));

        return array_map(function (array $row): CoverageCellView {
            $resourceType = (string) $row['resource_type'];
            $description = $this->resourceLabelProvider->describe($resourceType);

            return new CoverageCellView(
                date: (string) $row['record_date'],
                shopRef: (string) $row['shop_ref'],
                resourceType: $resourceType,
                resourceLabel: $description['label'],
                resourceGroup: $description['group'],
                rawCount: (int) $row['raw_count'],
                txCount: (int) $row['tx_count'],
                issueCount: (int) $row['issue_count'],
                lastFetchedAt: self::formatDateTime($row['last_fetched_at'] ?? null),
            );
        }, $rows);
    }

    /**
     * @param list<array<string, mixed>> $baseRows
     * @param list<array<string, mixed>> $issueRows
     *
     * @return list<array<string, mixed>>
     */
    private function mergeRows(array $baseRows, array $issueRows): array
    {
        $merged = [];

        foreach (array_merge($baseRows, $issueRows) as $row) {
            $key = implode('|', [
                (string) $row['record_date'],
                (string) $row['shop_ref'],
                (string) $row['resource_type'],
            ]);

            if (!isset($merged[$key])) {
                $merged[$key] = [
                    'record_date' => (string) $row['record_date'],
                    'shop_ref' => (string) $row['shop_ref'],
                    'resource_type' => (string) $row['resource_type'],
                    'raw_count' => 0,
                    'tx_count' => 0,
                    'issue_count' => 0,
                    'last_fetched_at' => null,
                ];
            }

            $merged[$key]['raw_count'] += (int) ($row['raw_count'] ?? 0);
            $merged[$key]['tx_count'] += (int) ($row['tx_count'] ?? 0);
            $merged[$key]['issue_count'] += (int) ($row['issue_count'] ?? 0);

            if (null !== ($row['last_fetched_at'] ?? null)) {
                $current = $merged[$key]['last_fetched_at'];
                if (null === $current || (string) $row['last_fetched_at'] > (string) $current) {
                    $merged[$key]['last_fetched_at'] = $row['last_fetched_at'];
                }
            }
        }

        $rows = array_values($merged);
        usort($rows, static fn (array $left, array $right): int => [
            (string) $left['record_date'],
            (string) $left['shop_ref'],
            (string) $left['resource_type'],
        ] <=> [
            (string) $right['record_date'],
            (string) $right['shop_ref'],
            (string) $right['resource_type'],
        ]);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function failedJobIssueRows(
        string $companyId,
        ?string $shopRef,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $shopFilter = null !== $shopRef && '' !== $shopRef ? 'AND j.shop_ref = :shopRef' : '';
        $sql = <<<SQL
            WITH failed_jobs AS (
                SELECT
                    j.id,
                    j.source,
                    j.shop_ref,
                    j.resource_type,
                    COALESCE(
                        j.window_from,
                        CASE
                            WHEN j.cursor_snapshot ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
                                THEN j.cursor_snapshot::date
                            ELSE j.created_at::date
                        END
                    ) AS from_date,
                    COALESCE(
                        j.window_to,
                        j.window_from,
                        CASE
                            WHEN j.cursor_snapshot ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
                                THEN j.cursor_snapshot::date
                            ELSE j.created_at::date
                        END
                    ) AS to_date
                FROM ingest_sync_jobs j
                WHERE j.company_id = :companyId
                  AND j.status = :failedStatus
                  AND (j.kind = :incrementalKind OR j.parent_job_id IS NOT NULL)
                  {$shopFilter}
            ),
            failed_issue_days AS (
                SELECT
                    fj.id,
                    fj.source,
                    fj.shop_ref,
                    fj.resource_type,
                    GREATEST(fj.from_date, :fromDate)::date AS record_date
                FROM failed_jobs fj
                WHERE fj.from_date <= :toDate
                  AND fj.to_date >= :fromDate
            )
            SELECT
                TO_CHAR(fid.record_date, 'YYYY-MM-DD') AS record_date,
                fid.shop_ref,
                fid.resource_type,
                0 AS raw_count,
                0 AS tx_count,
                COUNT(DISTINCT fid.id) AS issue_count,
                NULL AS last_fetched_at
            FROM failed_issue_days fid
            WHERE NOT EXISTS (
                SELECT 1
                FROM ingest_raw_records r
                LEFT JOIN ingest_sync_jobs successful_job
                    ON successful_job.company_id = r.company_id
                   AND successful_job.id::text = r.sync_job_id
                LEFT JOIN ingest_financial_transactions ft
                    ON ft.company_id = r.company_id
                   AND ft.raw_record_id = r.id
                WHERE r.company_id = :companyId
                  AND r.source = fid.source
                  AND r.resource_type = fid.resource_type
                  AND COALESCE(NULLIF(ft.shop_ref, ''), r.shop_ref) = fid.shop_ref
                  AND successful_job.status = :completedStatus
                  AND (
                      (
                          ft.id IS NOT NULL
                          AND ft.occurred_at >= fid.record_date::timestamp
                          AND ft.occurred_at < fid.record_date::timestamp + interval '1 day'
                      )
                      OR (
                          ft.id IS NULL
                          AND successful_job.window_from IS NOT NULL
                          AND successful_job.window_from <= fid.record_date
                          AND COALESCE(successful_job.window_to, successful_job.window_from) >= fid.record_date
                      )
                      OR (
                          ft.id IS NULL
                          AND successful_job.window_from IS NULL
                          AND r.fetched_at >= fid.record_date::timestamp
                          AND r.fetched_at < fid.record_date::timestamp + interval '1 day'
                      )
                  )
            )
            GROUP BY record_date, fid.shop_ref, fid.resource_type
            ORDER BY record_date ASC, fid.shop_ref ASC, fid.resource_type ASC
            SQL;

        $params = [
            'companyId' => $companyId,
            'failedStatus' => SyncJobStatus::FAILED->value,
            'completedStatus' => SyncJobStatus::COMPLETED->value,
            'incrementalKind' => SyncJobKind::INCREMENTAL->value,
            'fromDate' => $from,
            'toDate' => $to,
        ];
        $types = [
            'fromDate' => Types::DATE_IMMUTABLE,
            'toDate' => Types::DATE_IMMUTABLE,
        ];

        if (null !== $shopRef && '' !== $shopRef) {
            $params['shopRef'] = $shopRef;
        }

        return $this->connection->executeQuery($sql, $params, $types)->fetchAllAssociative();
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

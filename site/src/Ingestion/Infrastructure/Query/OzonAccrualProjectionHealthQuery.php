<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\RawNormalizationStatus;
use Doctrine\DBAL\Connection;

final readonly class OzonAccrualProjectionHealthQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rawProjectionRows(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?string $companyId,
        ?string $shopRef,
        int $limit,
        bool $problemsOnly,
    ): array {
        $externalWindowFrom = "substring(r.external_id from '^accrual-by-day:([0-9]{4}-[0-9]{2}-[0-9]{2}):[0-9]{4}-[0-9]{2}-[0-9]{2}$')::date";
        $externalWindowTo = "substring(r.external_id from '^accrual-by-day:[0-9]{4}-[0-9]{2}-[0-9]{2}:([0-9]{4}-[0-9]{2}-[0-9]{2})$')::date";
        $windowFrom = sprintf('COALESCE(j.window_from, %s, DATE(r.fetched_at))', $externalWindowFrom);
        $windowTo = sprintf('COALESCE(j.window_to, j.window_from, %s, %s, DATE(r.fetched_at))', $externalWindowTo, $externalWindowFrom);

        $conditions = [
            'r.source = :source',
            'r.resource_type = :resourceType',
            sprintf('%s <= :toDate', $windowFrom),
            sprintf('%s >= :fromDate', $windowTo),
        ];
        $params = [
            'source' => IngestSource::OZON->value,
            'resourceType' => OzonResourceType::ACCRUAL_BY_DAY,
            'fromDate' => $from->format('Y-m-d'),
            'toDate' => $to->format('Y-m-d'),
            'doneStatus' => RawNormalizationStatus::DONE->value,
        ];

        if (null !== $companyId) {
            $conditions[] = 'r.company_id = :companyId';
            $params['companyId'] = $companyId;
        }

        if (null !== $shopRef) {
            $conditions[] = 'r.shop_ref = :shopRef';
            $params['shopRef'] = $shopRef;
        }

        $problemPredicate = '(
            r.normalization_status <> :doneStatus
            OR COALESCE(tx.tx_count, 0) = 0
            OR COALESCE(issues.open_issues, 0) > 0
            OR (tx.last_tx_updated_at IS NOT NULL AND r.fetched_at > tx.last_tx_updated_at)
            OR (tx.last_tx_updated_at IS NOT NULL AND r.updated_at > tx.last_tx_updated_at)
        )';

        $problemFilter = $problemsOnly ? sprintf('AND %s', $problemPredicate) : '';

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "WITH tx AS (
                    SELECT company_id,
                           raw_record_id,
                           COUNT(*) AS tx_count,
                           MAX(updated_at) AS last_tx_updated_at
                    FROM ingest_financial_transactions
                    WHERE source = :source
                    GROUP BY company_id, raw_record_id
                ),
                issues AS (
                    SELECT company_id,
                           raw_record_id,
                           COUNT(*) FILTER (WHERE resolved_at IS NULL) AS open_issues
                    FROM ingest_normalization_issues
                    GROUP BY company_id, raw_record_id
                )
                SELECT r.company_id,
                       r.shop_ref,
                       r.id AS raw_id,
                       r.external_id,
                       r.normalization_status,
                       r.fetched_at,
                       r.last_seen_at,
                       r.updated_at AS raw_updated_at,
                       TO_CHAR(%s, 'YYYY-MM-DD') AS window_from,
                       TO_CHAR(%s, 'YYYY-MM-DD') AS window_to,
                       COALESCE(tx.tx_count, 0) AS tx_count,
                       tx.last_tx_updated_at,
                       COALESCE(issues.open_issues, 0) AS open_issues,
                       CASE WHEN %s THEN 1 ELSE 0 END AS needs_normalization
                FROM ingest_raw_records r
                LEFT JOIN ingest_sync_jobs j ON j.id::text = r.sync_job_id AND j.company_id = r.company_id
                LEFT JOIN tx ON tx.company_id = r.company_id AND tx.raw_record_id = r.id
                LEFT JOIN issues ON issues.company_id = r.company_id AND issues.raw_record_id = r.id
                WHERE %s
                %s
                ORDER BY needs_normalization DESC,
                         %s ASC,
                         %s ASC,
                         r.company_id ASC,
                         r.shop_ref ASC,
                         r.fetched_at ASC
                LIMIT %d",
                $windowFrom,
                $windowTo,
                $problemPredicate,
                implode(' AND ', $conditions),
                $problemFilter,
                $windowFrom,
                $windowTo,
                $limit,
            ),
            $params,
        );

        return $rows;
    }

    /**
     * @return array<string, int>
     */
    public function integritySummary(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?string $companyId,
        ?string $shopRef,
    ): array {
        $conditions = [
            'ft.source = :source',
            'ft.occurred_at >= :from',
            'ft.occurred_at < :toExclusive',
            "ft.external_id LIKE 'ozon:accrual-by-day:%'",
        ];
        $params = [
            'source' => IngestSource::OZON->value,
            'from' => $from->setTime(0, 0)->format('Y-m-d H:i:s'),
            'toExclusive' => $to->modify('+1 day')->setTime(0, 0)->format('Y-m-d H:i:s'),
        ];

        if (null !== $companyId) {
            $conditions[] = 'ft.company_id = :companyId';
            $params['companyId'] = $companyId;
        }

        if (null !== $shopRef) {
            $conditions[] = 'ft.shop_ref = :shopRef';
            $params['shopRef'] = $shopRef;
        }

        /** @var array<string, mixed>|false $row */
        $row = $this->connection->fetchAssociative(
            sprintf(
                "SELECT
                    COUNT(*) AS tx_count,
                    COUNT(*) FILTER (WHERE ft.listing_id IS NULL) AS unlinked_total,
                    COUNT(*) FILTER (
                        WHERE ft.listing_id IS NULL
                          AND split_part(ft.external_id, ':', 4) = 'non_item_fee'
                    ) AS unlinked_non_item_fee,
                    COUNT(*) FILTER (
                        WHERE ft.listing_id IS NOT NULL
                          AND ml.id IS NULL
                    ) AS broken_links,
                    COUNT(*) FILTER (
                        WHERE ft.listing_id IS NOT NULL
                          AND ft.listing_sku IS DISTINCT FROM ml.marketplace_sku
                    ) AS sku_mismatch
                 FROM ingest_financial_transactions ft
                 LEFT JOIN marketplace_listings ml
                   ON ml.id = ft.listing_id
                  AND ml.company_id = ft.company_id
                 WHERE %s",
                implode(' AND ', $conditions),
            ),
            $params,
        );

        if (!is_array($row)) {
            return [
                'txCount' => 0,
                'unlinkedTotal' => 0,
                'unlinkedNonItemFee' => 0,
                'brokenLinks' => 0,
                'skuMismatch' => 0,
            ];
        }

        return [
            'txCount' => (int) $row['tx_count'],
            'unlinkedTotal' => (int) $row['unlinked_total'],
            'unlinkedNonItemFee' => (int) $row['unlinked_non_item_fee'],
            'brokenLinks' => (int) $row['broken_links'],
            'skuMismatch' => (int) $row['sku_mismatch'],
        ];
    }
}

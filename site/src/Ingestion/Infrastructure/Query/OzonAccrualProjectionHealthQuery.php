<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\RawNormalizationStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class OzonAccrualProjectionHealthQuery
{
    public function __construct(
        private Connection $connection,
        private OzonAccrualRawRecordQuery $rawRecordQuery,
    ) {
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
        $rows = $this->rawRecordQuery->latestCoverageRows($companyId, $shopRef, $from, $to, 0);
        if ([] === $rows) {
            return [];
        }

        $details = $this->projectionDetails($rows);
        $result = [];
        foreach ($rows as $row) {
            $rawId = (string) $row['id'];
            $tx = $details['tx'][$rawId] ?? ['tx_count' => 0, 'last_tx_updated_at' => null];
            $directRawTxCount = (int) ($details['direct'][$rawId] ?? 0);
            $openIssues = (int) ($details['issues'][$rawId] ?? 0);
            $needsNormalization = RawNormalizationStatus::DONE->value !== (string) $row['normalization_status']
                || 0 === (int) $tx['tx_count']
                || 0 === $directRawTxCount
                || $openIssues > 0;

            if ($problemsOnly && !$needsNormalization) {
                continue;
            }

            $result[] = [
                'company_id' => (string) $row['company_id'],
                'shop_ref' => (string) $row['shop_ref'],
                'raw_id' => $rawId,
                'external_id' => (string) $row['external_id'],
                'normalization_status' => (string) $row['normalization_status'],
                'fetched_at' => $row['fetched_at'],
                'last_seen_at' => $row['last_seen_at'] ?? null,
                'raw_updated_at' => $row['raw_updated_at'] ?? null,
                'window_from' => (string) $row['window_from'],
                'window_to' => (string) $row['window_to'],
                'tx_count' => (int) $tx['tx_count'],
                'direct_raw_tx_count' => $directRawTxCount,
                'last_tx_updated_at' => $tx['last_tx_updated_at'],
                'open_issues' => $openIssues,
                'needs_normalization' => $needsNormalization ? 1 : 0,
            ];
        }

        usort($result, static fn (array $left, array $right): int => [
            -(int) $left['needs_normalization'],
            (string) $left['window_from'],
            (string) $left['window_to'],
            (string) $left['company_id'],
            (string) $left['shop_ref'],
            (string) $left['fetched_at'],
        ] <=> [
            -(int) $right['needs_normalization'],
            (string) $right['window_from'],
            (string) $right['window_to'],
            (string) $right['company_id'],
            (string) $right['shop_ref'],
            (string) $right['fetched_at'],
        ]);

        return array_slice($result, 0, $limit);
    }

    /**
     * @param list<array<string, mixed>> $rawRows
     *
     * @return array{tx: array<string, array{tx_count: int, last_tx_updated_at: mixed}>, direct: array<string, int>, issues: array<string, int>}
     */
    private function projectionDetails(array $rawRows): array
    {
        $rawIds = array_values(array_unique(array_map(static fn (array $row): string => (string) $row['id'], $rawRows)));

        return [
            'tx' => $this->txCountsByWindow($rawRows),
            'direct' => $this->directRawTxCounts($rawIds),
            'issues' => $this->openIssueCounts($rawIds),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rawRows
     *
     * @return array<string, array{tx_count: int, last_tx_updated_at: mixed}>
     */
    private function txCountsByWindow(array $rawRows): array
    {
        $params = ['source' => IngestSource::OZON->value];
        $values = $this->rawDateValues($rawRows, $params);
        if ('' === $values) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "WITH raw(raw_id, company_id, shop_ref, selected_date) AS (VALUES %s)
                 SELECT raw.raw_id,
                        COUNT(ft.id) AS tx_count,
                        MAX(ft.updated_at) AS last_tx_updated_at
                 FROM raw
                 LEFT JOIN ingest_financial_transactions ft
                   ON ft.company_id = raw.company_id
                  AND ft.shop_ref = raw.shop_ref
                  AND ft.source = :source
                  AND ft.external_id LIKE 'ozon:accrual-by-day:%%'
                  AND ft.occurred_at >= raw.selected_date
                  AND ft.occurred_at < raw.selected_date + INTERVAL '1 day'
                 GROUP BY raw.raw_id",
                $values,
            ),
            $params,
        );

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row['raw_id']] = [
                'tx_count' => (int) $row['tx_count'],
                'last_tx_updated_at' => $row['last_tx_updated_at'] ?? null,
            ];
        }

        return $indexed;
    }

    /**
     * @param list<string> $rawIds
     *
     * @return array<string, int>
     */
    private function directRawTxCounts(array $rawIds): array
    {
        if ([] === $rawIds) {
            return [];
        }

        $rows = $this->connection->executeQuery(
            'SELECT raw_record_id, COUNT(id) AS tx_count
             FROM ingest_financial_transactions
             WHERE source = :source
               AND raw_record_id IN (:rawIds)
             GROUP BY raw_record_id',
            [
                'source' => IngestSource::OZON->value,
                'rawIds' => $rawIds,
            ],
            [
                'rawIds' => ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row['raw_record_id']] = (int) $row['tx_count'];
        }

        return $indexed;
    }

    /**
     * @param list<string> $rawIds
     *
     * @return array<string, int>
     */
    private function openIssueCounts(array $rawIds): array
    {
        if ([] === $rawIds) {
            return [];
        }

        $rows = $this->connection->executeQuery(
            'SELECT raw_record_id, COUNT(id) FILTER (WHERE resolved_at IS NULL) AS open_issues
             FROM ingest_normalization_issues
             WHERE raw_record_id IN (:rawIds)
             GROUP BY raw_record_id',
            [
                'rawIds' => $rawIds,
            ],
            [
                'rawIds' => ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row['raw_record_id']] = (int) $row['open_issues'];
        }

        return $indexed;
    }

    /**
     * @param list<array<string, mixed>> $rawRows
     * @param array<string, mixed> $params
     */
    private function rawDateValues(array $rawRows, array &$params): string
    {
        $values = [];
        $index = 0;
        foreach ($rawRows as $row) {
            foreach ($this->selectedDates($row) as $date) {
                $params[sprintf('rawId%d', $index)] = (string) $row['id'];
                $params[sprintf('companyId%d', $index)] = (string) $row['company_id'];
                $params[sprintf('shopRef%d', $index)] = (string) $row['shop_ref'];
                $params[sprintf('selectedDate%d', $index)] = $date;
                $values[] = sprintf(
                    '(CAST(:rawId%d AS UUID), CAST(:companyId%d AS UUID), :shopRef%d, CAST(:selectedDate%d AS DATE))',
                    $index,
                    $index,
                    $index,
                    $index,
                );
                ++$index;
            }
        }

        return implode(', ', $values);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function selectedDates(array $row): array
    {
        $dates = [];
        if (is_array($row['selected_dates'] ?? null)) {
            foreach ($row['selected_dates'] as $date) {
                $date = trim((string) $date);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    $dates[$date] = $date;
                }
            }
        }

        if ([] !== $dates) {
            sort($dates);

            return array_values($dates);
        }

        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) ($row['window_from'] ?? ''), 0, 10));
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) ($row['window_to'] ?? ''), 0, 10));
        if (false === $from || false === $to || $from > $to) {
            return [];
        }

        for ($cursor = $from; $cursor <= $to; $cursor = $cursor->modify('+1 day')) {
            $dates[] = $cursor->format('Y-m-d');
        }

        return $dates;
    }

    /**
     * @param list<string> $externalIds
     *
     * @return array<string, true>
     */
    public function projectedExternalIdSet(string $companyId, string $shopRef, array $externalIds): array
    {
        if ([] === $externalIds) {
            return [];
        }

        $rows = $this->connection->executeQuery(
            'SELECT DISTINCT external_id
             FROM ingest_financial_transactions
             WHERE company_id = :companyId
               AND shop_ref = :shopRef
               AND source = :source
               AND external_id IN (:externalIds)',
            [
                'companyId' => $companyId,
                'shopRef' => $shopRef,
                'source' => IngestSource::OZON->value,
                'externalIds' => $externalIds,
            ],
            [
                'externalIds' => ArrayParameterType::STRING,
            ],
        )->fetchFirstColumn();

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row] = true;
        }

        return $result;
    }

    /**
     * @param list<string> $externalIds
     */
    public function reattributeProjectedExternalIds(
        string $companyId,
        string $shopRef,
        string $rawRecordId,
        \DateTimeImmutable $rawFetchedAt,
        array $externalIds,
    ): int {
        if ([] === $externalIds) {
            return 0;
        }

        return (int) $this->connection->executeStatement(
            'UPDATE ingest_financial_transactions
             SET raw_record_id = :rawRecordId,
                 external_updated_at = GREATEST(external_updated_at, CAST(:rawFetchedAt AS TIMESTAMP)),
                 updated_at = NOW()
             WHERE company_id = :companyId
               AND shop_ref = :shopRef
               AND source = :source
               AND external_id IN (:externalIds)
               AND external_updated_at <= CAST(:rawFetchedAt AS TIMESTAMP)
               AND raw_record_id <> :rawRecordId',
            [
                'companyId' => $companyId,
                'shopRef' => $shopRef,
                'rawRecordId' => $rawRecordId,
                'rawFetchedAt' => $rawFetchedAt->format('Y-m-d H:i:s.u'),
                'source' => IngestSource::OZON->value,
                'externalIds' => $externalIds,
            ],
            [
                'externalIds' => ArrayParameterType::STRING,
            ],
        );
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

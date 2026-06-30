<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use App\Ingestion\Application\Source\Ozon\OzonAccrualRawCoverageSelector;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\RawNormalizationStatus;
use Doctrine\DBAL\Connection;

final readonly class OzonAccrualRawRecordQuery
{
    private const WINDOW_FROM_SQL = "substring(r.external_id from '^accrual-by-day:([0-9]{4}-[0-9]{2}-[0-9]{2}):[0-9]{4}-[0-9]{2}-[0-9]{2}$')::date";
    private const WINDOW_TO_SQL = "substring(r.external_id from '^accrual-by-day:[0-9]{4}-[0-9]{2}-[0-9]{2}:([0-9]{4}-[0-9]{2}-[0-9]{2})$')::date";

    public function __construct(
        private Connection $connection,
        private OzonAccrualRawCoverageSelector $coverageSelector,
    ) {
    }

    /**
     * @param list<string>|null $statuses
     *
     * @return list<array<string, mixed>>
     */
    public function latestCoverageRows(
        ?string $companyId,
        ?string $shopRef,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $limit,
        ?array $statuses = null,
        int $offset = 0,
    ): array {
        $rows = $this->coverageSelector->selectLatest(
            $this->candidateRows($companyId, $shopRef, $from, $to),
            $from,
            $to,
        );

        if (null !== $statuses) {
            $statusMap = array_fill_keys($statuses, true);
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => isset($statusMap[(string) $row['normalization_status']]),
            ));
        }

        if ($offset > 0) {
            $rows = array_slice($rows, $offset);
        }

        return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function doneRawRecord(string $companyId, string $rawRecordId): ?array
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT r.id,
                        r.company_id,
                        r.resource_type,
                        r.external_id,
                        r.shop_ref,
                        r.fetched_at,
                        r.last_seen_at,
                        r.updated_at AS raw_updated_at,
                        r.created_at,
                        r.byte_size,
                        r.normalization_status,
                        TO_CHAR(%s, \'YYYY-MM-DD\') AS window_from,
                        TO_CHAR(%s, \'YYYY-MM-DD\') AS window_to
                 FROM ingest_raw_records r
                 WHERE r.id = :rawRecordId
                   AND r.company_id = :companyId
                   AND r.source = :source
                   AND r.resource_type = :resourceType
                   AND r.normalization_status = :doneStatus
                 LIMIT 1',
                self::WINDOW_FROM_SQL,
                self::WINDOW_TO_SQL,
            ),
            [
                'rawRecordId' => $rawRecordId,
                'companyId' => $companyId,
                'source' => IngestSource::OZON->value,
                'resourceType' => OzonResourceType::ACCRUAL_BY_DAY,
                'doneStatus' => RawNormalizationStatus::DONE->value,
            ],
        );

        return false === $row ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function candidateRows(
        ?string $companyId,
        ?string $shopRef,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $conditions = [
            'r.source = :source',
            'r.resource_type = :resourceType',
            'r.external_id ~ :externalIdPattern',
            sprintf('%s <= :toDate', self::WINDOW_FROM_SQL),
            sprintf('%s >= :fromDate', self::WINDOW_TO_SQL),
        ];
        $params = [
            'source' => IngestSource::OZON->value,
            'resourceType' => OzonResourceType::ACCRUAL_BY_DAY,
            'externalIdPattern' => '^accrual-by-day:[0-9]{4}-[0-9]{2}-[0-9]{2}:[0-9]{4}-[0-9]{2}-[0-9]{2}$',
            'fromDate' => $from->format('Y-m-d'),
            'toDate' => $to->format('Y-m-d'),
        ];

        if (null !== $companyId) {
            $conditions[] = 'r.company_id = :companyId';
            $params['companyId'] = $companyId;
        }

        if (null !== $shopRef && '' !== $shopRef) {
            $conditions[] = 'r.shop_ref = :shopRef';
            $params['shopRef'] = $shopRef;
        }

        return $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT r.id,
                        r.company_id,
                        r.resource_type,
                        r.external_id,
                        r.shop_ref,
                        r.fetched_at,
                        r.last_seen_at,
                        r.updated_at AS raw_updated_at,
                        r.created_at,
                        r.byte_size,
                        r.normalization_status,
                        TO_CHAR(%s, \'YYYY-MM-DD\') AS window_from,
                        TO_CHAR(%s, \'YYYY-MM-DD\') AS window_to
                 FROM ingest_raw_records r
                 WHERE %s
                 ORDER BY r.company_id ASC,
                          r.shop_ref ASC,
                          %s ASC,
                          %s ASC,
                          r.fetched_at ASC,
                          r.created_at ASC',
                self::WINDOW_FROM_SQL,
                self::WINDOW_TO_SQL,
                implode(' AND ', $conditions),
                self::WINDOW_FROM_SQL,
                self::WINDOW_TO_SQL,
            ),
            $params,
        );
    }
}

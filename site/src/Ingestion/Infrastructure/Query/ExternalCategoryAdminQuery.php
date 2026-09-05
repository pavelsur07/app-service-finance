<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Enum\ExternalCategoryStatus;
use App\Ingestion\Enum\IngestSource;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class ExternalCategoryAdminQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<array{source: string, resource_type: string, status: string, categories: int}>
     */
    public function statusSummary(): array
    {
        return array_map(
            static fn (array $row): array => [
                'source' => (string) $row['source'],
                'resource_type' => (string) $row['resource_type'],
                'status' => (string) $row['status'],
                'categories' => (int) $row['categories'],
            ],
            $this->connection->fetchAllAssociative(
                'SELECT source, resource_type, status, COUNT(*) AS categories
                 FROM ingest_external_categories
                 GROUP BY source, resource_type, status
                 ORDER BY source ASC, resource_type ASC, status ASC',
            ),
        );
    }

    /**
     * @return list<array<string, string|int|null>>
     */
    public function latestCategories(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        return array_map(
            static fn (array $row): array => [
                'id' => (string) $row['id'],
                'source' => (string) $row['source'],
                'resource_type' => (string) $row['resource_type'],
                'scope' => (string) $row['scope'],
                'normalized_key' => (string) $row['normalized_key'],
                'external_type_id' => null !== $row['external_type_id'] ? (string) $row['external_type_id'] : null,
                'external_code' => null !== $row['external_code'] ? (string) $row['external_code'] : null,
                'external_name' => null !== $row['external_name'] ? (string) $row['external_name'] : null,
                'provider_label' => null !== $row['provider_label'] ? (string) $row['provider_label'] : null,
                'display_label' => null !== $row['display_label'] ? (string) $row['display_label'] : null,
                'status' => (string) $row['status'],
                'seen_count' => (int) $row['seen_count'],
                'last_seen_at' => (string) $row['last_seen_at'],
                'canonical_code' => null !== $row['canonical_code'] ? (string) $row['canonical_code'] : null,
                'canonical_group' => null !== $row['canonical_group'] ? (string) $row['canonical_group'] : null,
                'canonical_label' => null !== $row['canonical_label'] ? (string) $row['canonical_label'] : null,
                'transaction_type' => null !== $row['transaction_type'] ? (string) $row['transaction_type'] : null,
                'sort_order' => null !== $row['sort_order'] ? (int) $row['sort_order'] : null,
                'known' => null !== $row['known'] ? (bool) $row['known'] : null,
                'mapping_status' => null !== $row['mapping_status'] ? (string) $row['mapping_status'] : null,
                'company_overrides' => (int) $row['company_overrides'],
            ],
            $this->connection->fetchAllAssociative(
                sprintf(
                    'SELECT c.id,
                            c.source,
                            c.resource_type,
                            c.scope,
                            c.normalized_key,
                            c.external_type_id,
                            c.external_code,
                            c.external_name,
                            c.provider_label,
                            c.display_label,
                            c.status,
                            c.seen_count,
                            c.last_seen_at,
                            m.canonical_code,
                            m.canonical_group,
                            m.canonical_label,
                            m.transaction_type,
                            m.sort_order,
                            m.known,
                            m.status AS mapping_status,
                            (
                                SELECT COUNT(*)
                                FROM ingest_external_category_company_mappings cm
                                WHERE cm.external_category_id = c.id
                                  AND cm.status = \'active\'
                            ) AS company_overrides
                     FROM ingest_external_categories c
                     LEFT JOIN ingest_external_category_mappings m ON m.external_category_id = c.id
                     ORDER BY CASE c.status
                                WHEN \'new\' THEN 0
                                WHEN \'needs_identification\' THEN 1
                                WHEN \'mapped\' THEN 2
                                WHEN \'ignored\' THEN 3
                                WHEN \'deprecated\' THEN 4
                                ELSE 9
                              END ASC,
                              c.last_seen_at DESC,
                              c.created_at DESC
                     LIMIT %d',
                    $limit,
                ),
            ),
        );
    }

    /**
     * Without a window the count is global (admin dashboard, status command).
     * The daily maintenance health gate passes its repair window so the gate
     * only fails on rows the maintenance run can actually rewrite.
     *
     * `orphan*` counts the subset that no registered external category can explain:
     * neither its external code nor its type id is queued for review in
     * `ingest_external_categories`. Discovery enqueues everything it can identify and
     * requires a type id to do so, so a leftover row means the taxonomy pipeline
     * itself produced something unidentifiable — nobody can map it, and that is an
     * incident. The remaining rows wait for a human to map an already visible
     * category: expected, and cleared by that mapping rather than by an alert.
     *
     * @return array{transactions: int, groups: int, orphanTransactions: int, orphanGroups: int}
     */
    public function unclassifiedOzonAccrualTransactions(?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): array
    {
        $params = [
            'source' => IngestSource::OZON->value,
            'resourceType' => OzonResourceType::ACCRUAL_BY_DAY,
        ];

        $window = '';
        if (null !== $from) {
            $window .= ' AND ft.occurred_at >= :fromAt';
            $params['fromAt'] = $from->format('Y-m-d 00:00:00');
        }

        if (null !== $to) {
            $window .= ' AND ft.occurred_at < :toExclusive';
            $params['toExclusive'] = $to->modify('+1 day')->format('Y-m-d 00:00:00');
        }

        $params['pendingStatuses'] = [
            ExternalCategoryStatus::NEW->value,
            ExternalCategoryStatus::NEEDS_IDENTIFICATION->value,
        ];

        // Идентичность строки и признак «сопоставить нечем» вычисляются по одному разу
        // во внутреннем запросе, а внешний только агрегирует. Копия предиката в каждом
        // COUNT рано или поздно разошлась бы с оригиналом.
        //
        // EXISTS, а не JOIN: категория может совпасть и по коду, и по type_id
        // одновременно, и join размножил бы строку, завысив COUNT(*).
        $row = $this->connection->fetchAssociative(
            "SELECT
                COUNT(*) AS transactions,
                COUNT(DISTINCT identity) AS groups,
                COUNT(*) FILTER (WHERE is_orphan) AS orphan_transactions,
                COUNT(DISTINCT identity) FILTER (WHERE is_orphan) AS orphan_groups
             FROM (
                SELECT
                    COALESCE(
                        NULLIF(ft.source_data->>'_ingestion_external_code', ''),
                        NULLIF(ft.source_data->>'_ingestion_provider_label', ''),
                        NULLIF(ft.source_data->>'_ozon_category_label', ''),
                        ft.description,
                        ft.type
                    ) AS identity,
                    NOT EXISTS (
                        SELECT 1
                        FROM ingest_external_categories ec
                        WHERE ec.source = :source
                          AND ec.resource_type = :resourceType
                          AND ec.status IN (:pendingStatuses)
                          AND (
                               lower(NULLIF(ec.external_code, '')) = lower(NULLIF(ft.source_data->>'_ingestion_external_code', ''))
                               OR NULLIF(ec.external_type_id, '') = NULLIF(ft.source_data->>'_ingestion_type_id', '')
                          )
                    ) AS is_orphan
                FROM ingest_financial_transactions ft
                WHERE ft.source = :source
                  AND ft.source_data->>'_ingestion_resource' = :resourceType
                  AND (
                       ft.source_data->>'_ozon_category_known' = 'false'
                       OR NULLIF(ft.source_data->>'_ozon_category_group', '') IS NULL
                       OR ft.source_data->>'_ozon_category_group' IN ('Неизвестные категории Ozon', 'Требует классификации', 'Без группы Ozon')
                       OR ft.source_data->>'_ozon_category_label' LIKE 'Неизвест%'
                       OR COALESCE(ft.description, '') LIKE 'Ozon accrual%'
                  )".$window.'
             ) unclassified',
            $params,
            ['pendingStatuses' => ArrayParameterType::STRING],
        );

        return [
            'transactions' => (int) ($row['transactions'] ?? 0),
            'groups' => (int) ($row['groups'] ?? 0),
            'orphanTransactions' => (int) ($row['orphan_transactions'] ?? 0),
            'orphanGroups' => (int) ($row['orphan_groups'] ?? 0),
        ];
    }
}

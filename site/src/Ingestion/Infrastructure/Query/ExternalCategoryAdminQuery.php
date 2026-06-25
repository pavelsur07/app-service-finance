<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Enum\IngestSource;
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
                'external_name' => null !== $row['external_name'] ? (string) $row['external_name'] : null,
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
            ],
            $this->connection->fetchAllAssociative(
                sprintf(
                    'SELECT c.id,
                            c.source,
                            c.resource_type,
                            c.scope,
                            c.normalized_key,
                            c.external_type_id,
                            c.external_name,
                            c.status,
                            c.seen_count,
                            c.last_seen_at,
                            m.canonical_code,
                            m.canonical_group,
                            m.canonical_label,
                            m.transaction_type,
                            m.sort_order,
                            m.known,
                            m.status AS mapping_status
                     FROM ingest_external_categories c
                     LEFT JOIN ingest_external_category_mappings m ON m.external_category_id = c.id
                     ORDER BY CASE c.status
                                WHEN \'new\' THEN 0
                                WHEN \'mapped\' THEN 1
                                WHEN \'ignored\' THEN 2
                                WHEN \'deprecated\' THEN 3
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
     * @return array{transactions: int, groups: int}
     */
    public function unclassifiedOzonAccrualTransactions(): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT
                COUNT(*) AS transactions,
                COUNT(DISTINCT COALESCE(NULLIF(ft.source_data->>'_ozon_category_label', ''), ft.description, ft.type)) AS groups
             FROM ingest_financial_transactions ft
             WHERE ft.source = :source
               AND ft.source_data->>'_ingestion_resource' = :resourceType
               AND (
                    ft.source_data->>'_ozon_category_known' = 'false'
                    OR NULLIF(ft.source_data->>'_ozon_category_group', '') IS NULL
                    OR ft.source_data->>'_ozon_category_group' IN ('Неизвестные категории Ozon', 'Требует классификации', 'Без группы Ozon')
                    OR ft.source_data->>'_ozon_category_label' LIKE 'Неизвест%'
                    OR COALESCE(ft.description, '') LIKE 'Ozon accrual%'
               )",
            [
                'source' => IngestSource::OZON->value,
                'resourceType' => OzonResourceType::ACCRUAL_BY_DAY,
            ],
        );

        return [
            'transactions' => (int) ($row['transactions'] ?? 0),
            'groups' => (int) ($row['groups'] ?? 0),
        ];
    }
}

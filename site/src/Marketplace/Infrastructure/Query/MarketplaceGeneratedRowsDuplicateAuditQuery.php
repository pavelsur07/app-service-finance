<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

final class MarketplaceGeneratedRowsDuplicateAuditQuery
{
    private const DUPLICATE_TARGETS = [
        'sales' => [
            'table' => 'marketplace_sales',
            'external_id_column' => 'external_order_id',
        ],
        'returns' => [
            'table' => 'marketplace_returns',
            'external_id_column' => 'external_return_id',
        ],
        'costs' => [
            'table' => 'marketplace_costs',
            'external_id_column' => 'external_id',
        ],
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function countSalesDuplicateGroups(): int
    {
        return $this->countDuplicateGroups('sales');
    }

    public function countReturnsDuplicateGroups(): int
    {
        return $this->countDuplicateGroups('returns');
    }

    public function countCostsDuplicateGroups(): int
    {
        return $this->countDuplicateGroups('costs');
    }

    public function findSalesDuplicateGroups(int $limit): array
    {
        return $this->findDuplicateGroups('sales', $limit);
    }

    public function findReturnsDuplicateGroups(int $limit): array
    {
        return $this->findDuplicateGroups('returns', $limit);
    }

    public function findCostsDuplicateGroups(int $limit): array
    {
        return $this->findDuplicateGroups('costs', $limit);
    }

    private function countDuplicateGroups(string $kind): int
    {
        ['table' => $tableName, 'external_id_column' => $externalIdColumn] = $this->resolveKindConfig($kind);

        $sql = sprintf(
            <<<'SQL'
            SELECT COUNT(*)
            FROM (
                SELECT 1
                FROM %s t
                WHERE t.%s IS NOT NULL
                  AND TRIM(t.%s) <> ''
                GROUP BY t.company_id, t.marketplace, t.%s
                HAVING COUNT(*) > 1
            ) duplicated_groups
            SQL,
            $tableName,
            $externalIdColumn,
            $externalIdColumn,
            $externalIdColumn,
        );

        return (int) $this->connection->fetchOne($sql);
    }

    private function findDuplicateGroups(string $kind, int $limit): array
    {
        ['table' => $tableName, 'external_id_column' => $externalIdColumn] = $this->resolveKindConfig($kind);

        $sql = sprintf(
            <<<'SQL'
            SELECT
                t.company_id,
                t.marketplace,
                t.%s AS external_id,
                COUNT(*) AS duplicate_count,
                ARRAY_AGG(t.id ORDER BY t.created_at ASC, t.id ASC) AS row_ids
            FROM %s t
            WHERE t.%s IS NOT NULL
              AND TRIM(t.%s) <> ''
            GROUP BY t.company_id, t.marketplace, t.%s
            HAVING COUNT(*) > 1
            ORDER BY duplicate_count DESC, t.company_id ASC, t.marketplace ASC, external_id ASC
            LIMIT :limit
            SQL,
            $externalIdColumn,
            $tableName,
            $externalIdColumn,
            $externalIdColumn,
            $externalIdColumn,
        );

        // ARRAY_AGG использован осознанно: запрос рассчитан на PostgreSQL, как и миграция unique index для raw documents.
        return $this->connection->fetchAllAssociative($sql, ['limit' => $limit], ['limit' => \PDO::PARAM_INT]);
    }

    private function resolveKindConfig(string $kind): array
    {
        $config = self::DUPLICATE_TARGETS[$kind] ?? null;
        if ($config === null) {
            throw new \InvalidArgumentException(sprintf('Unknown duplicate audit kind: %s', $kind));
        }

        return $config;
    }
}

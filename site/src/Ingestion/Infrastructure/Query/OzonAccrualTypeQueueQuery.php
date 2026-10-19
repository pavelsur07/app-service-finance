<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use App\Ingestion\Application\DTO\OzonAccrualTypeDisputeView;
use App\Ingestion\Application\DTO\OzonAccrualTypeUsageRow;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Enum\IngestSource;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Данные админской очереди новых типов Ozon и спорных групп.
 *
 * Выборка по всем компаниям сознательна: справочник, статьи и группы Ozon
 * глобальные, решение админа действует для всех. Наружу — агрегаты, не строки
 * компаний.
 */
final readonly class OzonAccrualTypeQueueQuery
{
    /**
     * Погашенная при реплее строка (`voidForReplay()`) не живая: ни в очередь, ни
     * в проверку типа операции при подтверждении она не идёт.
     */
    private const NOT_VOIDED = "COALESCE(ft.source_data->>'_ingestion_voided', 'false') <> 'true'";

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<OzonAccrualTypeUsageRow> по убыванию числа строк
     */
    public function unknownUsage(int $limit = 100): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT (ft.source_data->>'_ingestion_type_id')::int AS type_id,
                    COUNT(*) AS transactions,
                    TO_CHAR(MIN(ft.occurred_at), 'YYYY-MM-DD') AS first_date,
                    TO_CHAR(MAX(ft.occurred_at), 'YYYY-MM-DD') AS last_date,
                    MODE() WITHIN GROUP (ORDER BY ft.type) AS transaction_type
             FROM ingest_financial_transactions ft
             WHERE ft.source = :source
               AND ft.source_data->>'_ingestion_resource' = :resource
               AND ".self::NOT_VOIDED."
               AND ft.source_data->>'_ozon_category_known' = 'false'
               AND ft.source_data->>'_ingestion_type_id' ~ '^[0-9]+$'
             GROUP BY 1
             ORDER BY 2 DESC, 1
             LIMIT :limit",
            ['source' => IngestSource::OZON->value, 'resource' => OzonResourceType::ACCRUAL_BY_DAY, 'limit' => max(1, min(500, $limit))],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(
            static fn (array $row): OzonAccrualTypeUsageRow => new OzonAccrualTypeUsageRow(
                typeId: (int) $row['type_id'],
                transactions: (int) $row['transactions'],
                firstDate: (string) $row['first_date'],
                lastDate: (string) $row['last_date'],
                transactionType: (string) $row['transaction_type'],
            ),
            $rows,
        );
    }

    public function unknownUsageForType(int $typeId): ?OzonAccrualTypeUsageRow
    {
        $row = $this->connection->fetchAssociative(
            "SELECT COUNT(*) AS transactions,
                    TO_CHAR(MIN(ft.occurred_at), 'YYYY-MM-DD') AS first_date,
                    TO_CHAR(MAX(ft.occurred_at), 'YYYY-MM-DD') AS last_date,
                    MODE() WITHIN GROUP (ORDER BY ft.type) AS transaction_type
             FROM ingest_financial_transactions ft
             WHERE ft.source = :source
               AND ft.source_data->>'_ingestion_resource' = :resource
               AND ".self::NOT_VOIDED."
               AND ft.source_data->>'_ozon_category_known' = 'false'
               AND ft.source_data->>'_ingestion_type_id' = :typeId",
            ['source' => IngestSource::OZON->value, 'resource' => OzonResourceType::ACCRUAL_BY_DAY, 'typeId' => (string) $typeId],
        );
        if (false === $row || 0 === (int) $row['transactions']) {
            return null;
        }

        return new OzonAccrualTypeUsageRow($typeId, (int) $row['transactions'], (string) $row['first_date'], (string) $row['last_date'], (string) $row['transaction_type']);
    }

    /**
     * Типы транзакций строк `non_item_fee` этого типа Ozon — только у них тип
     * берётся из статьи, и только его нельзя менять без смены ключа.
     *
     * @return list<string>
     */
    public function nonItemTransactionTypes(int $typeId): array
    {
        /** @var list<string> $types */
        $types = $this->connection->fetchFirstColumn(
            "SELECT DISTINCT ft.type
             FROM ingest_financial_transactions ft
             WHERE ft.source = :source
               AND ft.source_data->>'_ingestion_resource' = :resource
               AND ".self::NOT_VOIDED."
               AND ft.source_data->>'_ingestion_type_id' = :typeId
               AND COALESCE(ft.source_data->>'_ingestion_component', '') LIKE 'non_item_fee%'
             ORDER BY 1",
            ['source' => IngestSource::OZON->value, 'resource' => OzonResourceType::ACCRUAL_BY_DAY, 'typeId' => (string) $typeId],
        );

        return $types;
    }

    /**
     * @return list<OzonAccrualTypeDisputeView>
     */
    public function disputes(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT g.type_id, t.name AS type_name, g.group_code, g.disputed_group_code,
                    COALESCE(cur.label, g.group_code) AS current_label,
                    COALESCE(dis.label, g.disputed_group_code) AS disputed_label,
                    g.votes, g.disputed_votes, g.disputed_company_id,
                    TO_CHAR(g.disputed_at, 'YYYY-MM-DD HH24:MI') AS disputed_at
             FROM ingest_ozon_accrual_type_groups g
             LEFT JOIN ingest_ozon_accrual_types t ON t.type_id = g.type_id
             LEFT JOIN ingest_category_groups cur ON cur.source = :source AND cur.code = g.group_code
             LEFT JOIN ingest_category_groups dis ON dis.source = :source AND dis.code = g.disputed_group_code
             WHERE g.disputed_group_code IS NOT NULL
             ORDER BY g.disputed_at DESC",
            ['source' => IngestSource::OZON->value],
        );

        return array_map(
            static fn (array $row): OzonAccrualTypeDisputeView => new OzonAccrualTypeDisputeView(
                typeId: (int) $row['type_id'],
                typeName: null !== $row['type_name'] ? (string) $row['type_name'] : null,
                currentGroupCode: (string) $row['group_code'],
                disputedGroupCode: (string) $row['disputed_group_code'],
                currentGroupLabel: (string) $row['current_label'],
                disputedGroupLabel: (string) $row['disputed_label'],
                currentVotes: (int) $row['votes'],
                disputedVotes: (int) $row['disputed_votes'],
                disputedCompanyId: null !== $row['disputed_company_id'] ? (string) $row['disputed_company_id'] : null,
                disputedAt: null !== $row['disputed_at'] ? (string) $row['disputed_at'] : null,
            ),
            $rows,
        );
    }
}

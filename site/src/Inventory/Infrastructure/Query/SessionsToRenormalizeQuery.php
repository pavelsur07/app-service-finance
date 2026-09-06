<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Сессии снапшотов, у которых остались строки без привязки к листингу.
 *
 * Маппинг замораживается в момент нормализации: ключ upsert включает snapshot_date,
 * поэтому листинг, появившийся позже, вчерашние строки не чинит. Этот запрос находит
 * ровно те сессии, для которых повторная нормализация что-то изменит.
 *
 * Берётся только ПОСЛЕДНЯЯ сессия дня по каждой паре «компания + источник» — тем же
 * правилом, что и в отчётном запросе остатков. Если за день было две сессии (на PROD
 * такие дни есть), пере-нормализация старой затёрла бы строки, записанные новой.
 */
final readonly class SessionsToRenormalizeQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<array{companyId: string, source: string, snapshotDate: string, snapshotSessionId: string, totalRows: int, unmappedRows: int}>
     */
    public function execute(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?string $source = null,
        ?string $companyId = null,
    ): array {
        $params = [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ];

        $filter = '';
        if (null !== $source) {
            $filter .= ' AND s.source = :source';
            $params['source'] = $source;
        }
        if (null !== $companyId) {
            $filter .= ' AND s.company_id = :companyId';
            $params['companyId'] = $companyId;
        }

        $rows = $this->connection->fetchAllAssociative(
            'WITH latest AS (
                SELECT DISTINCT ON (s.company_id, s.source, s.snapshot_date)
                    s.company_id,
                    s.source,
                    s.snapshot_date,
                    s.snapshot_session_id
                FROM inventory_stock_snapshots s
                WHERE s.snapshot_date BETWEEN :from AND :to'.$filter.'
                ORDER BY
                    s.company_id,
                    s.source,
                    s.snapshot_date,
                    s.snapshot_at DESC,
                    s.snapshot_session_id DESC
             )
             SELECT
                l.company_id AS company_id,
                l.source AS source,
                to_char(l.snapshot_date, \'YYYY-MM-DD\') AS snapshot_date,
                l.snapshot_session_id AS snapshot_session_id,
                count(*) AS total_rows,
                count(*) FILTER (WHERE s.mapping_status IS DISTINCT FROM \'mapped\') AS unmapped_rows
             FROM latest l
             INNER JOIN inventory_stock_snapshots s
                ON s.company_id = l.company_id
               AND s.source = l.source
               AND s.snapshot_date = l.snapshot_date
               AND s.snapshot_session_id = l.snapshot_session_id
             GROUP BY l.company_id, l.source, l.snapshot_date, l.snapshot_session_id
             HAVING count(*) FILTER (WHERE s.mapping_status IS DISTINCT FROM \'mapped\') > 0
             ORDER BY l.snapshot_date, l.source, l.company_id',
            $params,
        );

        return array_map(
            static fn (array $row): array => [
                'companyId' => (string) $row['company_id'],
                'source' => (string) $row['source'],
                'snapshotDate' => (string) $row['snapshot_date'],
                'snapshotSessionId' => (string) $row['snapshot_session_id'],
                'totalRows' => (int) $row['total_rows'],
                'unmappedRows' => (int) $row['unmapped_rows'],
            ],
            $rows,
        );
    }
}

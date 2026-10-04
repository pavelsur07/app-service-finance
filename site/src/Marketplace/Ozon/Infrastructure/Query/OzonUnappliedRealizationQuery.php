<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Infrastructure\Query;

use App\Marketplace\Enum\FinancialReportSyncStatus;
use Doctrine\DBAL\Connection;

/**
 * Документы «Реализации» Ozon, загруженные, но не применённые в учёт.
 *
 * Документ считается неприменённым, если в нём есть строки, первичная обработка не дошла до конца (`records_created = 0`)
 * и пара в таблице статусов не терминальна (`success`, `conflict`, `failed_final`, `auth_failed`): `conflict` — месяц закрыт, решение за человеком.
 * Пара в работе (`raw_loaded`, `processing`, `loading`, `queued`, `empty`) попадает в выборку только когда «залипла» (не менялась `$stuckAfter`),
 * `failed` — когда наступил срок повтора. Без активного seller-подключения документ не берётся: обработке нужна пара «компания × подключение».
 */
final readonly class OzonUnappliedRealizationQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param \DateTimeImmutable $monthFrom первый день самого раннего месяца
     * @param \DateTimeImmutable $monthTo первый день самого позднего месяца
     * @param \DateTimeImmutable|null $loadedBefore только документы, загруженные не позже этого момента (для гейта «висит больше суток»)
     *
     * @return list<array{documentId: string, companyId: string, connectionId: string, year: int, month: int, status: ?string, syncedAt: string}>
     */
    public function find(
        \DateTimeImmutable $monthFrom,
        \DateTimeImmutable $monthTo,
        \DateTimeImmutable $now,
        \DateInterval $stuckAfter,
        ?\DateTimeImmutable $loadedBefore = null,
        ?int $limit = null,
    ): array {
        $sql = <<<'SQL'
            SELECT d.id AS document_id, d.company_id, c.id AS connection_id, d.period_from, d.synced_at, s.status
            FROM marketplace_raw_documents d
            INNER JOIN marketplace_connections c
                ON c.company_id = d.company_id AND c.marketplace = 'ozon' AND c.connection_type = 'seller' AND c.is_active = TRUE
            LEFT JOIN marketplace_financial_report_sync_statuses s
                ON s.company_id = d.company_id AND s.marketplace = 'ozon' AND s.report_type = 'ozon_realization' AND s.business_date = d.period_from
            WHERE d.marketplace = 'ozon'
              AND d.document_type = 'realization'
              AND d.period_from >= :monthFrom
              AND d.period_from <= :monthTo
              AND d.records_count > 0
              AND d.records_created = 0
              AND (
                    s.id IS NULL
                 OR (s.status = :failed AND (s.next_retry_at IS NULL OR s.next_retry_at <= :now))
                 OR (s.status IN (:inProgress1, :inProgress2, :inProgress3, :inProgress4, :inProgress5) AND s.updated_at <= :stuckBefore)
              )
            SQL;
        $params = [
            'monthFrom' => $monthFrom->format('Y-m-d'),
            'monthTo' => $monthTo->format('Y-m-d'),
            'failed' => FinancialReportSyncStatus::FAILED->value,
            'now' => $now->format('Y-m-d H:i:s'),
            'stuckBefore' => $now->sub($stuckAfter)->format('Y-m-d H:i:s'),
            'inProgress1' => FinancialReportSyncStatus::RAW_LOADED->value,
            'inProgress2' => FinancialReportSyncStatus::PROCESSING->value,
            'inProgress3' => FinancialReportSyncStatus::LOADING->value,
            'inProgress4' => FinancialReportSyncStatus::QUEUED->value,
            'inProgress5' => FinancialReportSyncStatus::EMPTY->value,
        ];

        if (null !== $loadedBefore) {
            $sql .= ' AND d.synced_at <= :loadedBefore';
            $params['loadedBefore'] = $loadedBefore->format('Y-m-d H:i:s');
        }

        $sql .= ' ORDER BY d.period_from DESC, d.company_id ASC, d.id ASC';
        if (null !== $limit) {
            $sql .= ' LIMIT '.max(1, $limit);
        }

        $result = [];
        foreach ($this->connection->fetchAllAssociative($sql, $params) as $row) {
            $period = new \DateTimeImmutable((string) $row['period_from']);
            $result[] = [
                'documentId' => (string) $row['document_id'],
                'companyId' => (string) $row['company_id'],
                'connectionId' => (string) $row['connection_id'],
                'year' => (int) $period->format('Y'),
                'month' => (int) $period->format('n'),
                'status' => null === $row['status'] ? null : (string) $row['status'],
                'syncedAt' => (string) $row['synced_at'],
            ];
        }

        return $result;
    }
}

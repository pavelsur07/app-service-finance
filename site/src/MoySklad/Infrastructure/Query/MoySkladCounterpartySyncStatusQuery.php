<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

final readonly class MoySkladCounterpartySyncStatusQuery
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @param list<string> $connectionIds
     * @return array<string, array{status: ?string, startedAt: ?\DateTimeImmutable, finishedAt: ?\DateTimeImmutable, processed: int, created: int, updated: int, unchanged: int, errorCategory: ?string, lastCompletedAt: ?\DateTimeImmutable, history: list<array{status: string, startedAt: \DateTimeImmutable, finishedAt: ?\DateTimeImmutable, processed: int, created: int, updated: int, unchanged: int, errorCategory: ?string}>}>
     */
    public function forConnections(string $companyId, array $connectionIds): array
    {
        if ([] === $connectionIds) {
            return [];
        }
        $rows = $this->em->getConnection()->executeQuery(<<<'SQL'
            SELECT c.id AS connection_id, r.status, r.started_at, r.finished_at,
                   r.processed, r.created, r.updated, r.unchanged, r.error_category,
                   cursor.last_completed_at
            FROM moysklad_connections c
            LEFT JOIN LATERAL (
                SELECT status, started_at, finished_at, processed, created, updated, unchanged, error_category
                FROM moysklad_sync_runs r
                WHERE r.company_id = c.company_id AND r.connection_id = c.id AND r.entity_type = 'counterparty'
                ORDER BY r.started_at DESC, r.id DESC
                LIMIT 1
            ) r ON true
            LEFT JOIN moysklad_sync_cursors cursor
              ON cursor.company_id = c.company_id AND cursor.connection_id = c.id AND cursor.entity_type = 'counterparty'
            WHERE c.company_id = :companyId AND c.id IN (:connectionIds)
            SQL, ['companyId' => $companyId, 'connectionIds' => $connectionIds], ['connectionIds' => ArrayParameterType::STRING])->fetchAllAssociative();

        $statuses = [];
        foreach ($rows as $row) {
            $statuses[(string) $row['connection_id']] = [
                'status' => is_string($row['status']) ? $row['status'] : null,
                'startedAt' => $this->utcDate($row['started_at']),
                'finishedAt' => $this->utcDate($row['finished_at']),
                'processed' => (int) $row['processed'],
                'created' => (int) $row['created'],
                'updated' => (int) $row['updated'],
                'unchanged' => (int) $row['unchanged'],
                'errorCategory' => is_string($row['error_category']) ? $row['error_category'] : null,
                'lastCompletedAt' => $this->utcDate($row['last_completed_at']),
                'history' => [],
            ];
        }

        $historyRows = $this->em->getConnection()->executeQuery(<<<'SQL'
            SELECT connection_id, status, started_at, finished_at, processed, created, updated, unchanged, error_category
            FROM (
                SELECT r.connection_id, r.status, r.started_at, r.finished_at,
                       r.processed, r.created, r.updated, r.unchanged, r.error_category,
                       ROW_NUMBER() OVER (PARTITION BY r.connection_id ORDER BY r.started_at DESC, r.id DESC) AS row_number
                FROM moysklad_sync_runs r
                WHERE r.company_id = :companyId AND r.connection_id IN (:connectionIds) AND r.entity_type = 'counterparty'
            ) ranked
            WHERE row_number <= 5
            ORDER BY connection_id, row_number
            SQL, ['companyId' => $companyId, 'connectionIds' => $connectionIds], ['connectionIds' => ArrayParameterType::STRING])->fetchAllAssociative();
        foreach ($historyRows as $row) {
            $connectionId = (string) $row['connection_id'];
            if (!isset($statuses[$connectionId]) || !is_string($row['status'])) {
                continue;
            }
            $startedAt = $this->utcDate($row['started_at']);
            if (null === $startedAt) {
                continue;
            }
            $statuses[$connectionId]['history'][] = [
                'status' => $row['status'],
                'startedAt' => $startedAt,
                'finishedAt' => $this->utcDate($row['finished_at']),
                'processed' => (int) $row['processed'],
                'created' => (int) $row['created'],
                'updated' => (int) $row['updated'],
                'unchanged' => (int) $row['unchanged'],
                'errorCategory' => is_string($row['error_category']) ? $row['error_category'] : null,
            ];
        }

        return $statuses;
    }

    private function utcDate(mixed $value): ?\DateTimeImmutable
    {
        return is_string($value) ? new \DateTimeImmutable($value, new \DateTimeZone('UTC')) : null;
    }
}

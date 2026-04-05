<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Read-model: последний processing run для набора raw documents.
 * Используется для отображения статуса pipeline в списке документов.
 */
final class RawProcessingRunsListQuery
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * Возвращает последний run для каждого документа из списка.
     *
     * @param  string[] $rawDocumentIds
     * @return array<string, array{id: string, status: string, pipeline_trigger: string, started_at: string, finished_at: string|null, last_error_message: string|null}>
     *         Ключ — raw_document_id
     */
    public function fetchLatestForDocuments(string $companyId, array $rawDocumentIds): array
    {
        if (empty($rawDocumentIds)) {
            return [];
        }

        $rows = $this->connection->executeQuery(
            'SELECT r.id, r.raw_document_id, r.status, r.pipeline_trigger,
                    r.started_at, r.finished_at, r.last_error_message
             FROM marketplace_raw_processing_runs r
             WHERE r.company_id = :companyId
               AND r.raw_document_id IN (:docIds)
             ORDER BY r.started_at DESC',
            ['companyId' => $companyId, 'docIds' => $rawDocumentIds],
            ['docIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        // Берём первую строку по каждому docId — она наибольшая по started_at (ORDER BY DESC)
        $result = [];
        foreach ($rows as $row) {
            $docId = $row['raw_document_id'];
            if (!isset($result[$docId])) {
                $result[$docId] = $row;
            }
        }

        return $result;
    }
}

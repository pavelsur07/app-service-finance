<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Read-model: полные данные одного processing run + его шагов.
 * Используется для детальной страницы статуса pipeline.
 */
final class RawProcessingRunDetailsQuery
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * Возвращает данные run и его шагов.
     * null — если run не найден или не принадлежит companyId (IDOR-safe).
     *
     * @return array{
     *     id: string,
     *     raw_document_id: string,
     *     status: string,
     *     pipeline_trigger: string,
     *     started_at: string,
     *     finished_at: string|null,
     *     last_error_message: string|null,
     *     summary: array|null,
     *     details: array|null,
     *     steps: list<array{
     *         id: string,
     *         step: string,
     *         status: string,
     *         started_at: string|null,
     *         finished_at: string|null,
     *         processed_count: int,
     *         failed_count: int,
     *         skipped_count: int,
     *         error_message: string|null,
     *     }>
     * }|null
     */
    public function fetch(string $companyId, string $runId): ?array
    {
        $run = $this->connection->fetchAssociative(
            'SELECT id, raw_document_id, status, pipeline_trigger,
                    started_at, finished_at, last_error_message, summary, details
             FROM marketplace_raw_processing_runs
             WHERE id = ? AND company_id = ?',
            [$runId, $companyId],
        );

        if ($run === false) {
            return null;
        }

        $run['summary'] = $run['summary'] !== null
            ? json_decode($run['summary'], true, 512, JSON_THROW_ON_ERROR) : null;
        $run['details'] = $run['details'] !== null
            ? json_decode($run['details'], true, 512, JSON_THROW_ON_ERROR) : null;

        $steps = $this->connection->executeQuery(
            'SELECT id, step, status, started_at, finished_at,
                    processed_count, failed_count, skipped_count, error_message
             FROM marketplace_raw_processing_step_runs
             WHERE processing_run_id = ? AND company_id = ?
             ORDER BY created_at ASC',
            [$runId, $companyId],
        )->fetchAllAssociative();

        $run['steps'] = $steps;

        return $run;
    }
}

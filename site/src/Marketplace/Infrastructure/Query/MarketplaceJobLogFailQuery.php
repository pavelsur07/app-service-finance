<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Перевод записи журнала в FAILED напрямую через DBAL.
 *
 * ORM-репозиторий здесь непригоден: `EntityManager::wrapInTransaction()` при
 * ошибке вызывает `close()`, и последующий `persist()` бросил бы
 * EntityManagerClosed — запись осталась бы RUNNING навсегда, а исходное
 * исключение подменилось бы техническим. DBAL-соединение закрытием EM не
 * затрагивается.
 */
final class MarketplaceJobLogFailQuery
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function markFailed(string $jobLogId, string $companyId, string $reason): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
            UPDATE marketplace_job_logs
               SET status = 'failed',
                   finished_at = :now,
                   summary = CAST(:summary AS JSON),
                   details = CAST('[]' AS JSON)
             WHERE id = :id
               AND company_id = :company_id
            SQL,
            [
                'id' => $jobLogId,
                'company_id' => $companyId,
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'summary' => json_encode(['error' => $reason], \JSON_THROW_ON_ERROR),
            ],
        );
    }
}
